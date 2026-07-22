<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeConnect;
use Dono\Gateways\Stripe\StripeConnectAccount;
use Dono\Gateways\TestMode;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stripe Connect onboarding via the Dono broker: the plugin never holds the
 * platform secret. The broker runs the OAuth exchange and redirects back with
 * the connected account's tokens, stored encrypted.
 */
final class StripeConnectController
{
    private const NAMESPACE = 'dono/v1';
    private const STATE_TTL = 600;

    public function __construct(
        private StripeApi $api,
        private StripeConnectAccount $account,
        private TestMode $testMode,
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
        // Key the transient by the state, not the current user: the broker
        // redirects the browser to a public callback where no user is
        // authenticated (current id 0), so a user-scoped key would never be
        // found there. The random state is itself the single-use CSRF token.
        set_transient($this->stateKey($state), '1', self::STATE_TTL);

        // Connect in whichever mode the org runs in: a site in test mode links
        // a Stripe test account, live links live. forForm(null) reads the
        // org-wide dono_gateway_config.test_mode flag.
        $mode = $this->testMode->forForm(null) ? 'test' : 'live';

        $url = add_query_arg([
            'state'      => $state,
            'return_url' => rawurlencode($this->callbackUrl()),
            'mode'       => $mode,
        ], StripeConnect::brokerUrl() . '/stripe/authorize');

        return new WP_REST_Response(['url' => $url], 200);
    }

    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        $settingsUrl = admin_url('admin.php?page=dono-settings#gateways');

        // Named connect_error, not error: WordPress strips the reserved `error`
        // query var before the callback runs, so the broker reports its failure
        // reason under a non-reserved key.
        $connectError = (string) $request->get_param('connect_error');
        if ($connectError !== '') {
            // access_denied = the owner declined consent; anything else (a
            // restricted/rejected account, an expired or reused code) is a
            // failure to authorize, which the UI phrases differently.
            return $this->redirect($settingsUrl, $connectError === 'access_denied' ? 'denied' : 'oauth_failed');
        }

        $state = (string) $request->get_param('state');
        $key   = $this->stateKey($state);
        $known = $state !== '' && get_transient($key) !== false;
        delete_transient($key);

        if (! $known) {
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

        // The broker populates only the linked mode's token pair. Tell the
        // account which mode we just connected so the immediate flag retrieve
        // authenticates with that token, instead of failing safe to test (a
        // live connect would otherwise 401 and wait for an account webhook).
        $isTest = (string) ($payload['stripe_access_token_test'] ?? '') !== '';
        $this->account->useTestMode($isTest);
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

    private function stateKey(string $state): string
    {
        return 'dono_stripe_oauth_state_' . hash('sha256', $state);
    }

    private function redirect(string $base, string $status): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', add_query_arg('dono_connect', $status, $base));
        return $response;
    }
}
