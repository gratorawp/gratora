<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeConnect;
use Dono\Gateways\Stripe\StripeConnectAccount;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stripe Connect onboarding via the Dono broker. The plugin never holds the
 * platform secret; the broker runs the OAuth exchange and redirects back with
 * the connected account's tokens, stored encrypted.
 *
 *   GET  /connect/stripe/status      admin: connection state
 *   POST /connect/stripe/authorize   admin: broker authorize URL
 *   GET  /connect/stripe/callback    public: broker redirect with tokens
 *   POST /connect/stripe/disconnect  admin: deauthorize and forget
 *   POST /connect/stripe/dev-connect admin + WP_DEBUG: paste a test token
 *
 * @version 1.0.0
 */
final class StripeConnectController
{
    private const NAMESPACE = 'dono/v1';
    private const STATE_TTL = 600;

    public function __construct(
        private StripeApi $api,
        private StripeConnectAccount $account,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/connect/stripe/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/connect/stripe/authorize', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'authorize'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/connect/stripe/callback', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'callback'],
            'permission_callback' => '__return_true', // browser redirect from broker; state is the gate
        ]);

        register_rest_route(self::NAMESPACE, '/connect/stripe/disconnect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'disconnect'],
            'permission_callback' => [$this, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/connect/stripe/dev-connect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'devConnect'],
            'permission_callback' => [$this, 'canManageDev'],
            'args'                => [
                'account_id'       => ['type' => 'string', 'required' => true],
                'test_token'       => ['type' => 'string', 'required' => true],
                'publishable_test' => ['type' => 'string'],
            ],
        ]);
    }

    public function canManage(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    /** Dev-only token paste, so local testing works before the broker is live. */
    public function canManageDev(): bool
    {
        return Capabilities::userCan('dono_manage_settings') && defined('WP_DEBUG') && WP_DEBUG;
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response([
            'platform_ready' => true,
            'connected'      => $this->account->isConnected(),
            'can_charge'     => $this->account->canCharge(),
            'dev_mode'       => defined('WP_DEBUG') && WP_DEBUG,
            'account'        => $this->account->get(),
        ], 200);
    }

    public function authorize(): WP_REST_Response
    {
        $state = bin2hex(random_bytes(16));
        set_transient($this->stateKey(), $state, self::STATE_TTL);

        $url = add_query_arg([
            'state'      => $state,
            'return_url' => rawurlencode($this->callbackUrl()),
        ], StripeConnect::brokerUrl() . '/stripe/authorize');

        return new WP_REST_Response(['url' => $url], 200);
    }

    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        $settingsUrl = admin_url('admin.php?page=dono-settings#gateways');

        if ((string) $request->get_param('error') !== '') {
            return $this->redirect($settingsUrl, 'denied');
        }

        $state  = (string) $request->get_param('state');
        $stored = get_transient($this->stateKey());
        delete_transient($this->stateKey());

        if ($state === '' || ! is_string($stored) || ! hash_equals($stored, $state)) {
            return $this->redirect($settingsUrl, 'invalid_state');
        }

        $exchangeCode = (string) $request->get_param('exchange_code');
        if ($exchangeCode === '') {
            return $this->redirect($settingsUrl, 'claim_failed');
        }

        // Claim the tokens server-to-server so they never travel in a
        // browser URL / access log / history. One-time, short-TTL code.
        $resp = wp_remote_post(StripeConnect::brokerUrl() . '/stripe/claim', [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => (string) wp_json_encode(['exchange_code' => $exchangeCode]),
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return $this->redirect($settingsUrl, 'claim_failed');
        }

        $payload = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (! is_array($payload) || (string) ($payload['stripe_user_id'] ?? '') === '') {
            return $this->redirect($settingsUrl, 'claim_failed');
        }

        $this->account->store([
            'stripe_user_id'              => (string) $payload['stripe_user_id'],
            'stripe_access_token'         => (string) ($payload['stripe_access_token']         ?? ''),
            'stripe_access_token_test'    => (string) ($payload['stripe_access_token_test']    ?? ''),
            'stripe_publishable_key'      => (string) ($payload['stripe_publishable_key']      ?? ''),
            'stripe_publishable_key_test' => (string) ($payload['stripe_publishable_key_test'] ?? ''),
        ]);
        $this->refreshAccountFlags();

        return $this->redirect($settingsUrl, 'connected');
    }

    public function disconnect(): WP_REST_Response
    {
        $acctId = $this->account->accountId();
        if ($acctId === null) {
            return new WP_REST_Response(['ok' => true, 'already' => true], 200);
        }

        // Local state is cleared regardless, so a failed broker call can't
        // leave the org stuck showing "connected".
        $resp = wp_remote_post(StripeConnect::brokerUrl() . '/stripe/deauthorize', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => (string) wp_json_encode(['stripe_user_id' => $acctId]),
        ]);
        if (is_wp_error($resp)) {
            error_log('[dono] Stripe broker deauthorize failed: ' . $resp->get_error_message());
        }

        $this->account->forget();
        return new WP_REST_Response(['ok' => true], 200);
    }

    public function devConnect(WP_REST_Request $request): WP_REST_Response
    {
        $this->account->store([
            'stripe_user_id'             => (string) $request->get_param('account_id'),
            'stripe_access_token'        => '',
            'stripe_access_token_test'   => (string) $request->get_param('test_token'),
            'stripe_publishable_key'     => '',
            'stripe_publishable_key_test' => (string) ($request->get_param('publishable_test') ?? ''),
        ]);
        $this->refreshAccountFlags();
        return new WP_REST_Response(['ok' => true, 'account' => $this->account->get()], 200);
    }

    /**
     * Pull the account object so the settings screen can show
     * charges/payouts/verification immediately. Best-effort: webhooks keep
     * it fresh afterwards.
     */
    private function refreshAccountFlags(): void
    {
        $acctId = $this->account->accountId();
        if ($acctId === null) return;
        try {
            $obj = $this->api->get('/accounts/' . rawurlencode($acctId));
            if (is_array($obj)) $this->account->refresh($obj);
        } catch (RuntimeException $e) {
            // Non-fatal; account.updated webhook will fill it in.
        }
    }

    private function callbackUrl(): string
    {
        return rest_url(self::NAMESPACE . '/connect/stripe/callback');
    }

    private function stateKey(): string
    {
        return 'dono_stripe_oauth_state_' . get_current_user_id();
    }

    private function redirect(string $base, string $status): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', add_query_arg('dono_connect', $status, $base));
        return $response;
    }
}
