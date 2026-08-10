<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Analytics\ErrorLog;
use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\Stripe\ApplePayDomain;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeWebhookProvisioner;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stripe setup with the organization's own API keys. Keys are verified against
 * Stripe before they are stored, so a typo is caught at save time rather than
 * at the first donation. Secret keys are write-only over REST: they go in, and
 * only a last-4 hint ever comes back.
 *
 * @since 1.0.0
 */
final class StripeKeysController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private StripeApi $api,
        private StripeAccount $account,
        private ApplePayDomain $applePay,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/gateways/stripe/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/stripe/keys', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'saveKeys'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => ['test', 'live'],
                ],
                'secret_key'      => ['type' => 'string', 'required' => true],
                'publishable_key' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/stripe/keys', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'removeKeys'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode' => ['type' => 'string', 'enum' => ['test', 'live', 'all'], 'default' => 'all'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/stripe/apple-pay', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'enableApplePay'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode' => ['type' => 'string', 'enum' => ['test', 'live'], 'default' => 'live'],
                'association_file' => ['type' => 'string'],
            ],
        ]);
    }

    /**
     * Store Apple's association file, then ask Stripe to register and verify
     * the domain. Both halves have to be true before Apple Pay renders, so this
     * does them together and reports whichever one is missing.
     *
     * @since 1.0.0
     */
    public function enableApplePay(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $test = $request->get_param('mode') === 'test';
        $file = trim((string) ($request->get_param('association_file') ?? ''));

        if ($file !== '') {
            $this->applePay->storeAssociationFile($file);
        }

        if (! $this->applePay->isFileReady()) {
            return new WP_Error(
                'dono_apple_pay_no_file',
                __('Paste the domain association file from Stripe first. Apple checks for it before the button can appear.', 'dono'),
                ['status' => 400]
            );
        }

        try {
            $result = $this->applePay->registerDomain($test);
        } catch (RuntimeException $e) {
            // Already registered is not a failure: re-check instead.
            try {
                $result = $this->applePay->refresh($test);
            } catch (RuntimeException $inner) {
                return new WP_Error('dono_apple_pay_failed', $inner->getMessage(), ['status' => 400]);
            }
        }

        return new WP_REST_Response([
            'apple_pay' => $result,
            'domain'    => $this->applePay->domain(),
        ], 200);
    }

    /** @since 1.0.0 */
    public function canManage(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    /** @since 1.0.0 */
    public function status(): WP_REST_Response
    {
        return new WP_REST_Response([
            'connected'   => $this->account->isConnected(),
            'can_charge'  => $this->account->canCharge(),
            'account'     => $this->account->get(),
            'webhook_url' => rest_url('dono/v1/webhooks/stripe'),
            'has_webhook_secret' => $this->api->hasWebhookSecret(),
            'apple_pay' => [
                'domain'    => $this->applePay->domain(),
                'has_file'  => $this->applePay->isFileReady(),
                'test'      => $this->applePay->status(true),
                'live'      => $this->applePay->status(false),
            ],
        ], 200);
    }

    /**
     * Store a key pair and test it against Stripe, putting the previous pair
     * back if it is rejected. The retrieve doubles as the credential check and
     * as the source of the account's display details and charges_enabled flag.
     *
     * @since 1.0.0
     */
    public function saveKeys(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $test        = $request->get_param('mode') === 'test';
        $secret      = trim((string) $request->get_param('secret_key'));
        $publishable = trim((string) $request->get_param('publishable_key'));

        if ($err = $this->validateShape($test, $secret, $publishable)) {
            return $err;
        }

        // The API client reads its key from the account, so the pair has to be
        // written before it can be tested. Snapshot first: a rejected key, or a
        // Stripe that is simply unreachable, must not cost an org the working
        // keys it was rotating away from. Blanking the mode instead would take
        // live payments down until someone re-entered them by hand.
        $previous = $this->account->snapshot();

        $this->account->saveKeys($test, $secret, $publishable);
        $this->account->useTestMode($test);

        try {
            $obj = $this->api->get('/account');
        } catch (RuntimeException $e) {
            $this->account->restore($previous);
            return new WP_Error(
                'dono_stripe_key_rejected',
                sprintf(
                    /* translators: %s: error message from Stripe */
                    __('Stripe rejected that secret key: %s', 'dono'),
                    $e->getMessage()
                ),
                ['status' => 400]
            );
        }

        $this->account->refresh(is_array($obj) ? $obj : []);
        $this->provisionWebhook($test);

        return $this->status();
    }

    /** @since 1.0.0 */
    public function removeKeys(WP_REST_Request $request): WP_REST_Response
    {
        $mode = (string) $request->get_param('mode');
        if ($mode === 'all') {
            $this->account->forget();
        } else {
            $this->account->forgetMode($mode === 'test');
        }
        return $this->status();
    }

    /**
     * Catch the two mistakes that actually happen: pasting the publishable key
     * into the secret field, and pasting live keys into the test slot.
     *
     * @since 1.0.0
     */
    private function validateShape(bool $test, string $secret, string $publishable): ?WP_Error
    {
        $bad = static fn (string $msg): WP_Error => new WP_Error('dono_stripe_bad_key', $msg, ['status' => 400]);

        if (! preg_match('/^(sk|rk)_(test|live)_/', $secret)) {
            return $bad(__('That does not look like a Stripe secret key. It starts with sk_test_ or sk_live_.', 'dono'));
        }
        if (! str_starts_with($publishable, 'pk_')) {
            return $bad(__('That does not look like a Stripe publishable key. It starts with pk_test_ or pk_live_.', 'dono'));
        }

        $secretIsTest      = str_contains($secret, '_test_');
        $publishableIsTest = str_starts_with($publishable, 'pk_test_');

        if ($secretIsTest !== $publishableIsTest) {
            return $bad(__('The secret and publishable keys are from different modes. Use the pair from the same Stripe mode.', 'dono'));
        }
        if ($secretIsTest !== $test) {
            return $bad(
                $test
                    ? __('Those are live keys. Paste your test keys here, or save them under Live.', 'dono')
                    : __('Those are test keys. Paste your live keys here, or save them under Test.', 'dono')
            );
        }
        return null;
    }

    /**
     * Register Dono's webhook endpoint on the org's own account so paid, refund
     * and renewal events flow without hand-building it in the Stripe dashboard.
     * Best effort: an unreachable (local) site keeps the manual signing-secret
     * path, and a failure must never block saving working keys.
     *
     * @since 1.0.0
     */
    private function provisionWebhook(bool $isTest): void
    {
        try {
            (new StripeWebhookProvisioner($this->api, $this->account))->provision($isTest);
        } catch (\Throwable $e) {
            ErrorLog::record('gateway.stripe', 'Webhook auto-provision failed: ' . $e->getMessage());
        }
    }
}
