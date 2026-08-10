<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
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
 * @since 1.0.0
 */
final class PayPalKeysController
{
    private const NAMESPACE = 'dono/v1';

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
            'args'                => [
                'mode'          => ['type' => 'string', 'required' => true, 'enum' => ['test', 'live']],
                'client_id'     => ['type' => 'string', 'required' => true],
                'client_secret' => ['type' => 'string', 'required' => true],
                'webhook_id'    => ['type' => 'string'],
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
        $test      = $request->get_param('mode') === 'test';
        $clientId  = trim((string) $request->get_param('client_id'));
        $secret    = trim((string) $request->get_param('client_secret'));
        $webhookId = trim((string) ($request->get_param('webhook_id') ?? ''));

        if ($clientId === '' || $secret === '') {
            return new WP_Error(
                'dono_paypal_bad_key',
                __('Enter both the client id and the secret.', 'dono'),
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
        $this->account->useTestMode($test);

        // A token fetch is the credential check: it fails for a wrong secret and
        // for credentials from the other environment, since sandbox and live are
        // different hosts entirely.
        try {
            $this->api->accessToken();
        } catch (RuntimeException $e) {
            $this->account->restore($previous);
            return new WP_Error(
                'dono_paypal_key_rejected',
                sprintf(
                    /* translators: 1: sandbox or live, 2: error from PayPal */
                    __('PayPal rejected those %1$s credentials: %2$s', 'dono'),
                    $test ? __('sandbox', 'dono') : __('live', 'dono'),
                    $e->getMessage()
                ),
                ['status' => 400]
            );
        }

        if ($webhookId !== '') {
            $this->account->saveWebhookId($test, $webhookId);
        }

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
}
