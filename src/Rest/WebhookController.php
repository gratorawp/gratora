<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Analytics\ErrorLog;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\WebhookOutcome;
use Dono\Vendor\Queryable\QueryException;
use Dono\Webhooks\WebhookLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Incoming-webhook dispatcher: POST /dono/v1/webhooks/{gateway}; handleWebhook()
 * verifies the signature. Dedup relies on the (gateway, external_id) UNIQUE index,
 * not a SELECT pre-check, which would race under concurrent redeliveries.
 *
 * @since 1.0.0
 */
final class WebhookController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private GatewayManager $gateways,
        private Clock $clock,
    ) {
    }

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
    public function dispatch(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $gatewayId = (string) $request['gateway'];
        $gateway = $this->gateways->get($gatewayId);

        if (! $gateway) {
            /* translators: %s: gateway identifier */
            return new WP_Error('dono_unknown_gateway', sprintf(__('Unknown gateway: %s', 'dono'), $gatewayId), ['status' => 404]);
        }

        try {
            $outcome = $gateway->handleWebhook($request);
        } catch (\Throwable $e) {
            // An out-of-order or malformed event must not fatal the endpoint;
            // record a delivery row and 500 so the gateway retries.
            ErrorLog::record('webhook.' . $gatewayId, $e->getMessage());
            $outcome = new WebhookOutcome(
                signature_ok: true,
                event_type:   'exception',
                error:        'Handler exception: ' . $e->getMessage(),
                http_status:  500,
            );
        }

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

    /** @since 1.0.0 */
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
        // forever), so non-duplicate insert failures are recorded to the
        // error log and swallowed.
        $prevSuppress = $wpdb ? $wpdb->suppress_errors(true) : false;
        try {
            $log->save();
        } catch (QueryException $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') === false) {
                ErrorLog::record('webhook.log', $e->getMessage(), ['gateway' => $gateway]);
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

}
