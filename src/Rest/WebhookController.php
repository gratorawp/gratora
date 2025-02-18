<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Vendor\Queryable\QueryException;
use Dono\Webhooks\WebhookLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Generic incoming-webhook dispatcher.
 *
 *   POST /wp-json/dono/v1/webhooks/{gateway}
 *
 * The resolved gateway's handleWebhook() verifies the signature and dispatches
 * the event. Dedup relies on the (gateway, external_id) UNIQUE index rather
 * than a SELECT pre-check, which would race under concurrent redeliveries.
 *
 * @version 1.0.0
 */
final class WebhookController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private GatewayManager $gateways,
        private Clock $clock,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/webhooks/(?P<gateway>[a-z0-9_-]+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'dispatch'],
            'permission_callback' => '__return_true',  // gateways aren't authenticated by WP; signature is the auth layer
            'args'                => [
                'gateway' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function dispatch(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $gatewayId = (string) $request['gateway'];
        $gateway = $this->gateways->get($gatewayId);

        if (! $gateway) {
            /* translators: %s: gateway identifier */
            return new WP_Error('dono_unknown_gateway', sprintf(__('Unknown gateway: %s', 'dono'), $gatewayId), ['status' => 404]);
        }

        $this->debugLog("webhook received: gateway={$gatewayId}");

        $outcome = $gateway->handleWebhook($request);

        $this->debugLog("webhook processed: gateway={$gatewayId} event={$outcome->event_type} handled=" . ($outcome->handled ? 'yes' : 'no') . " sig_ok=" . ($outcome->signature_ok ? 'yes' : 'no'));

        $this->logDelivery($gatewayId, $request, $outcome);

        if (! $outcome->signature_ok && $outcome->http_status >= 400) {
            return new WP_Error(
                'dono_webhook_rejected',
                $outcome->error ?? __('Webhook rejected.', 'dono'),
                ['status' => $outcome->http_status]
            );
        }

        if ($outcome->error !== null && $outcome->http_status >= 500) {
            return new WP_Error(
                'dono_webhook_error',
                $outcome->error,
                ['status' => $outcome->http_status]
            );
        }

        return new WP_REST_Response([
            'received'    => true,
            'handled'     => $outcome->handled,
            'event_type'  => $outcome->event_type,
        ], $outcome->http_status);
    }

    private function logDelivery(string $gateway, WP_REST_Request $request, $outcome): void
    {
        global $wpdb;

        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        $log = WebhookLog::make();
        $log->gateway      = $gateway;
        $log->external_id  = $outcome->external_id ?? '';
        $log->event_type   = $outcome->event_type ?? 'unknown';
        $log->signature_ok = $outcome->signature_ok;
        $log->payload      = (string) $request->get_body();
        $log->headers      = $headers;
        $log->processed    = $outcome->handled;
        $log->processed_at = $outcome->handled ? $this->clock->now()->format('Y-m-d H:i:s') : null;
        $log->error        = $outcome->error;
        $log->received_at  = $this->clock->now()->format('Y-m-d H:i:s');

        // Dedup via the UNIQUE index: Queryable throws QueryException on the
        // expected "Duplicate entry" for a redelivered event. Logging must
        // never fail the webhook response (a 5xx makes the gateway retry
        // forever), so non-duplicate insert failures are swallowed too, after
        // recording them to the debug log.
        $prevSuppress = $wpdb ? $wpdb->suppress_errors(true) : false;
        try {
            $log->save();
        } catch (QueryException $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') === false) {
                $this->debugLog('webhook log insert failed: ' . $e->getMessage());
            }
            if ($wpdb) {
                $wpdb->last_error = '';
            }
        } finally {
            if ($wpdb) {
                $wpdb->suppress_errors($prevSuppress);
            }
        }
    }

    private function debugLog(string $message): void
    {
        $cfg = get_option('dono_advanced', []);
        if (is_array($cfg) && ! empty($cfg['debug_logging'])) {
            error_log('[dono] ' . $message);
        }
    }
}
