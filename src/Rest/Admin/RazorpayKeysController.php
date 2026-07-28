<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\Razorpay\RazorpayAccount;
use Dono\Gateways\Razorpay\RazorpayApi;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Razorpay setup with the organisation's own API keys.
 *
 * Keys are verified against Razorpay before they are stored, so a typo is
 * caught at save time rather than at the first donation. The key secret and the
 * webhook secret are write-only over REST: they go in, and only a last-4 hint
 * ever comes back.
 */
final class RazorpayKeysController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private RazorpayApi $api,
        private RazorpayAccount $account,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/gateways/razorpay/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/razorpay/keys', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'saveKeys'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode'           => ['type' => 'string', 'required' => true, 'enum' => ['test', 'live']],
                'key_id'         => ['type' => 'string', 'required' => true],
                'key_secret'     => ['type' => 'string', 'required' => true],
                'webhook_secret' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/razorpay/keys', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'removeKeys'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode' => ['type' => 'string', 'enum' => ['test', 'live', 'all'], 'default' => 'all'],
            ],
        ]);
    }

    public function canManage(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response([
            'connected'   => $this->account->isConnected(),
            'can_charge'  => $this->account->canCharge(),
            'account'     => $this->account->get(),
            'webhook_url' => rest_url('dono/v1/webhooks/razorpay'),
        ], 200);
    }

    public function saveKeys(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $test          = $request->get_param('mode') === 'test';
        $keyId         = trim((string) $request->get_param('key_id'));
        $keySecret     = trim((string) $request->get_param('key_secret'));
        $webhookSecret = trim((string) ($request->get_param('webhook_secret') ?? ''));

        if ($err = $this->validateShape($test, $keyId, $keySecret)) {
            return $err;
        }

        $this->account->saveKeys($test, $keyId, $keySecret);
        $this->account->useTestMode($test);

        // Listing one payment is the cheapest call that needs working keys, and
        // it fails the same way for a wrong secret as for a revoked key.
        try {
            $this->api->get('/v1/payments?count=1');
        } catch (RuntimeException $e) {
            $this->account->forgetMode($test);
            return new WP_Error(
                'dono_razorpay_key_rejected',
                sprintf(
                    /* translators: 1: test or live, 2: error from Razorpay */
                    __('Razorpay rejected those %1$s keys: %2$s', 'dono'),
                    $test ? __('test', 'dono') : __('live', 'dono'),
                    $e->getMessage()
                ),
                ['status' => 400]
            );
        }

        if ($webhookSecret !== '') {
            $this->account->saveWebhookSecret($test, $webhookSecret);
        }

        return $this->status();
    }

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
     * The key id carries its own environment, so a live key saved under Test
     * would quietly charge real cards on a form the admin believes is safe.
     * That is the one mistake worth refusing outright.
     */
    private function validateShape(bool $test, string $keyId, string $keySecret): ?WP_Error
    {
        $bad = static fn (string $msg): WP_Error => new WP_Error('dono_razorpay_bad_key', $msg, ['status' => 400]);

        if ($keyId === '' || $keySecret === '') {
            return $bad(__('Enter both the key id and the key secret.', 'dono'));
        }
        if (! preg_match('/^rzp_(test|live)_/', $keyId)) {
            return $bad(__('That does not look like a Razorpay key id. It starts with rzp_test_ or rzp_live_.', 'dono'));
        }

        $keyIsTest = str_starts_with($keyId, 'rzp_test_');
        if ($keyIsTest !== $test) {
            return $bad(
                $test
                    ? __('That is a live key. Paste your test key here, or save it under Live.', 'dono')
                    : __('That is a test key. Paste your live key here, or save it under Test.', 'dono')
            );
        }

        // The secret is opaque, but pasting the key id into both fields is the
        // slip that happens, and it is unambiguous.
        if (str_starts_with($keySecret, 'rzp_')) {
            return $bad(__('That is the key id again, not the key secret. The secret has no rzp_ prefix.', 'dono'));
        }

        return null;
    }
}
