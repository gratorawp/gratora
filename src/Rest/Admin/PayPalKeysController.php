<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Analytics\ErrorLog;
use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\GatewayTransportException;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * PayPal setup with the organization's own REST app credentials.
 *
 * Credentials are exchanged for a token against PayPal before they are stored,
 * so a wrong client id or a sandbox key pasted into live is caught at save time
 * rather than at the first donation. The secret is write-only over REST.
 *
 * The webhook id is checked the same way, because PayPal's signature
 * verification replays it on every delivery: an id that PayPal does not know
 * rejects every event, and nothing about a donation says why.
 *
 * @since 1.0.0
 */
final class PayPalKeysController
{
    private const NAMESPACE = 'dono/v1';

    private const TIMEOUT     = 8;
    private const MIN_TIMEOUT = 3;
    private const TIME_MARGIN = 3;

    private const HOOK_FOUND   = 'found';
    private const HOOK_MISSING = 'missing';
    private const HOOK_UNKNOWN = 'unknown';

    /** The webhook exists but does not deliver everything this reads. */
    private const HOOK_INCOMPLETE = 'incomplete';

    /**
     * Events this plugin acts on. A webhook missing any of them leaves the
     * matching money movement unrecorded, and the donor's own screens saying so.
     *
     * @var list<string>
     */
    private const REQUIRED_EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.DENIED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'BILLING.SUBSCRIPTION.UPDATED',
    ];

    /** When the save that is running now began, for the outbound time budget. */
    private ?float $startedAt = null;

    /** @since 1.0.0 */
    public function __construct(
        private PayPalApi $api,
        private PayPalAccount $account,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/gateways/paypal/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/paypal/keys', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'saveKeys'],
            'permission_callback' => [$this, 'canManage'],
            // The credentials are optional at the schema level so a webhook id
            // can be added on its own against keys that are already saved.
            // saveKeys() holds the pairing rule.
            'args'                => [
                'mode'          => ['type' => 'string', 'required' => true, 'enum' => ['test', 'live']],
                'client_id'     => ['type' => 'string'],
                'client_secret' => ['type' => 'string'],
                'webhook_id'    => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/paypal/webhook', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'removeWebhookId'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode' => ['type' => 'string', 'required' => true, 'enum' => ['test', 'live']],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/gateways/paypal/keys', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'removeKeys'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'mode' => ['type' => 'string', 'enum' => ['test', 'live', 'all'], 'default' => 'all'],
            ],
        ]);
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
            'can_charge'  => $this->account->isConnected(),
            'account'     => $this->account->get(),
            'webhook_url' => rest_url('dono/v1/webhooks/paypal'),
        ], 200);
    }

    /** @since 1.0.0 */
    public function saveKeys(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->startedAt = microtime(true);

        $test      = $request->get_param('mode') === 'test';
        $clientId  = trim((string) ($request->get_param('client_id') ?? ''));
        $secret    = trim((string) ($request->get_param('client_secret') ?? ''));
        $webhookId = trim((string) ($request->get_param('webhook_id') ?? ''));

        $this->account->useTestMode($test);

        if ($clientId === '' && $secret === '' && $webhookId !== '') {
            return $this->saveWebhookIdAlone($test, $webhookId);
        }

        if ($clientId === '' || $secret === '') {
            return new WP_Error(
                'dono_paypal_bad_key',
                __('Enter both the client id and the secret.', 'dono-fundraising-platform'),
                ['status' => 400]
            );
        }

        // Credentials have to be stored before they can be tested, because the
        // token call reads them from the account. Keep the working set so a
        // transient failure during a routine rotation does not take the mode
        // down with it: forgetMode() blanks the webhook id too, and without
        // that id recurring stops being offered at all.
        $previous = $this->account->snapshot();

        $this->account->saveKeys($test, $clientId, $secret);

        // A token fetch is the credential check: it fails for a wrong secret and
        // for credentials from the other environment, since sandbox and live are
        // different hosts entirely.
        try {
            $this->api->accessToken();
        } catch (GatewayTransportException $e) {
            // Nothing was shown to PayPal, so the pair is neither good nor bad.
            // Calling it rejected sends an org to replace credentials that were
            // never the problem.
            $this->account->restore($previous);
            return new WP_Error(
                'dono_paypal_unreachable',
                sprintf(
                    /* translators: 1: sandbox or live, 2: transport error, e.g. a DNS failure */
                    __('This site could not reach PayPal, so the %1$s credentials have not been checked or saved: %2$s. That is a problem with this server rather than with the credentials.', 'dono-fundraising-platform'),
                    $this->modeLabel($test),
                    $e->getMessage()
                ),
                ['status' => 503]
            );
        } catch (RuntimeException $e) {
            $this->account->restore($previous);
            return new WP_Error(
                'dono_paypal_key_rejected',
                sprintf(
                    /* translators: 1: sandbox or live, 2: error from PayPal */
                    __('PayPal rejected those %1$s credentials: %2$s', 'dono-fundraising-platform'),
                    $this->modeLabel($test),
                    $e->getMessage()
                ),
                ['status' => 400]
            );
        }

        if ($webhookId === '') {
            return $this->status();
        }

        $check = $this->checkWebhookId($webhookId);

        if ($check['status'] === self::HOOK_FOUND || $check['status'] === self::HOOK_INCOMPLETE) {
            $this->account->saveWebhookId($test, $webhookId);
            $this->noteIncompleteWebhook($check);

            return $this->status();
        }

        // Credentials PayPal has just accepted stay saved: a problem with an
        // optional field must not cost an org the pair it proved. The id itself
        // is not written, and any id already on file is left where it is, since
        // one PayPal refuses (or cannot be asked about) rejects every delivery
        // exactly like a missing one.
        return $this->unsavedWebhookResponse($test, $webhookId, $check);
    }

    /**
     * Attach a webhook id to credentials already on file.
     *
     * Without that id the verification call has nothing to replay and every
     * delivery is refused, and an org whose keys went in months ago should not
     * have to produce the secret again to put it right.
     *
     * @since 1.0.0
     */
    private function saveWebhookIdAlone(bool $test, string $webhookId): WP_REST_Response|WP_Error
    {
        if (! $this->account->hasKeysFor($test)) {
            return new WP_Error(
                'dono_paypal_bad_key',
                sprintf(
                    /* translators: %s: sandbox or live */
                    __('Save the %s client id and secret first. A webhook id can only be checked against the app it belongs to.', 'dono-fundraising-platform'),
                    $this->modeLabel($test)
                ),
                ['status' => 400]
            );
        }

        $check = $this->checkWebhookId($webhookId);

        if ($check['status'] === self::HOOK_FOUND || $check['status'] === self::HOOK_INCOMPLETE) {
            $this->account->saveWebhookId($test, $webhookId);
            $this->noteIncompleteWebhook($check);

            return $this->status();
        }

        if ($check['status'] === self::HOOK_MISSING) {
            return $this->missingWebhookError($test, $check['message']);
        }

        // Nothing else is being saved here, so an unchecked id is refused
        // outright rather than parked on file under a screen that would then
        // read as though the webhook were set up.
        return new WP_Error(
            'dono_paypal_webhook_unchecked',
            sprintf(
                /* translators: %s: reason PayPal could not be asked */
                __('PayPal could not be asked whether that webhook id is right: %s. It has not been saved, because an id PayPal does not know rejects every notification. Try again in a moment.', 'dono-fundraising-platform'),
                $check['message']
            ),
            ['status' => 503]
        );
    }

    /**
     * Drop the webhook id for one mode, leaving the credentials on file.
     *
     * @since 1.0.0
     */
    public function removeWebhookId(WP_REST_Request $request): WP_REST_Response
    {
        $test = $request->get_param('mode') === 'test';
        $this->account->useTestMode($test);

        if ($this->account->webhookId($test) !== '') {
            $this->account->saveWebhookId($test, '');
        }

        return $this->status();
    }

    /**
     * Ask PayPal whether the webhook id exists under the credentials for the
     * mode being saved.
     *
     * The HTTP status is the whole point of the call, so it goes out directly
     * rather than through PayPalApi, which folds every response over 400 into
     * a single exception. A 404 says the id is not in this account; a 500, a
     * refused token or a timeout say nothing about the id at all, and must not
     * be reported as if they did.
     *
     * @return array{status:string,message:string}
     *
     * @since 1.0.0
     */
    /**
     * Say so where the org reads about problems, and save the id anyway.
     *
     * Refusing would block a setup that is otherwise right: an org taking only
     * one-time donations needs none of the subscription events, and this cannot
     * tell that from a webhook built wrong. What it can do is stop reporting an
     * unchecked id as checked.
     *
     * @param array{status:string,message:string} $check
     *
     * @since 1.0.0
     */
    private function noteIncompleteWebhook(array $check): void
    {
        if ($check['status'] !== self::HOOK_INCOMPLETE) {
            return;
        }

        ErrorLog::record('gateway.paypal', $check['message']);
    }

    /**
     * Which of the events this reads the webhook does not send.
     *
     * A subscription to every event PayPal has is expressed as a single `*`,
     * which covers everything.
     *
     * @param  array<string,mixed> $webhook as PayPal returns it
     * @return list<string>
     *
     * @since 1.0.0
     */
    private function eventsNotSubscribed(array $webhook): array
    {
        $names = [];
        foreach ((array) ($webhook['event_types'] ?? []) as $type) {
            $name = is_array($type) ? (string) ($type['name'] ?? '') : (string) $type;
            if ($name !== '') {
                $names[] = strtoupper(trim($name));
            }
        }

        if ($names === [] || in_array('*', $names, true)) {
            return [];
        }

        return array_values(array_diff(self::REQUIRED_EVENTS, $names));
    }

    private function checkWebhookId(string $webhookId): array
    {
        try {
            $token = $this->api->accessToken();
        } catch (RuntimeException $e) {
            return ['status' => self::HOOK_UNKNOWN, 'message' => $e->getMessage()];
        }

        $response = wp_remote_get(
            $this->api->baseUrl() . '/v1/notifications/webhooks/' . rawurlencode($webhookId),
            [
                'timeout' => $this->lookupTimeout(),
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return ['status' => self::HOOK_UNKNOWN, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $found = json_decode((string) wp_remote_retrieve_body($response), true);
            $missing = $this->eventsNotSubscribed(is_array($found) ? $found : []);

            // A webhook that exists is not a webhook that delivers anything
            // this reads. Reported as checked, an org can save an id subscribed
            // to nothing Dono handles and be told it is fine, and then every
            // recurring donation is charged with no event to bank it.
            if ($missing !== []) {
                return [
                    'status'  => self::HOOK_INCOMPLETE,
                    'message' => sprintf(
                        /* translators: %s: comma-separated PayPal event names */
                        __('That webhook does not send: %s', 'dono-fundraising-platform'),
                        implode(', ', $missing)
                    ),
                ];
            }

            return ['status' => self::HOOK_FOUND, 'message' => ''];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        $name = strtoupper(trim((string) ($body['name'] ?? '')));
        $why  = (string) ($body['message'] ?? $body['error_description'] ?? sprintf('HTTP %d', $code));

        // An id that is not in the account comes back 404; one that is not even
        // shaped like an id comes back 400 with the resource-id name. Both mean
        // the same thing to the admin: this value will never verify an event.
        $missing = $code === 404
            || ($code === 400 && in_array($name, ['INVALID_RESOURCE_ID', 'RESOURCE_NOT_FOUND'], true));

        return ['status' => $missing ? self::HOOK_MISSING : self::HOOK_UNKNOWN, 'message' => $why];
    }

    /**
     * The credentials went in, the webhook id did not. Success carries the
     * reason, naming the id that was turned away so the admin can tell which
     * value the screen is still missing.
     *
     * @param array{status:string,message:string} $check
     *
     * @since 1.0.0
     */
    private function unsavedWebhookResponse(bool $test, string $webhookId, array $check): WP_REST_Response
    {
        $response = $this->status();

        $warning = $check['status'] === self::HOOK_MISSING
            ? sprintf(
                /* translators: 1: the webhook id that was entered, 2: sandbox or live, 3: error from PayPal */
                __('The credentials are saved, but the webhook id %1$s is not: your %2$s PayPal app has no webhook with that id. Sandbox and live webhooks have separate ids, and the webhook id is not the WH- event id beside it in the dashboard. PayPal said: %3$s', 'dono-fundraising-platform'),
                $webhookId,
                $this->modeLabel($test),
                $check['message']
            )
            : sprintf(
                /* translators: 1: the webhook id that was entered, 2: reason PayPal could not be asked */
                __('The credentials are saved, but the webhook id %1$s is not: PayPal could not be asked whether it is right (%2$s). Add it again once PayPal answers.', 'dono-fundraising-platform'),
                $webhookId,
                $check['message']
            );

        $data = $response->get_data();
        $data['webhook_warning'] = $warning;
        $response->set_data($data);

        return $response;
    }

    /** @since 1.0.0 */
    private function missingWebhookError(bool $test, string $reason): WP_Error
    {
        return new WP_Error(
            'dono_paypal_webhook_rejected',
            sprintf(
                /* translators: 1: sandbox or live, 2: error from PayPal */
                __('Your %1$s PayPal app has no webhook with that id. Sandbox and live webhooks have separate ids, and the webhook id is not the WH- event id beside it in the dashboard. PayPal said: %2$s', 'dono-fundraising-platform'),
                $this->modeLabel($test),
                $reason
            ),
            ['status' => 400]
        );
    }

    /**
     * Keep the lookup inside what is left of the request's execution budget.
     * The token exchange ahead of it can spend most of a 30 second limit on its
     * own, and a request killed mid-save tells the admin nothing at all.
     *
     * @since 1.0.0
     */
    private function lookupTimeout(): int
    {
        $max = (int) ini_get('max_execution_time');
        if ($max <= 0) {
            return self::TIMEOUT;
        }

        $spent = (int) ceil(microtime(true) - ($this->startedAt ?? microtime(true)));

        return max(self::MIN_TIMEOUT, min(self::TIMEOUT, $max - $spent - self::TIME_MARGIN));
    }

    /** @since 1.0.0 */
    private function modeLabel(bool $test): string
    {
        return $test ? __('sandbox', 'dono-fundraising-platform') : __('live', 'dono-fundraising-platform');
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
}
