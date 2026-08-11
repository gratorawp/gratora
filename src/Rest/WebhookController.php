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

    /** One recorded refusal per gateway per window. */
    private const REJECT_NOTICE_KEY = 'dono_webhook_rejected_';
    private const REJECT_NOTICE_TTL = 15 * MINUTE_IN_SECONDS;

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
            // The delivery row alone is not enough: it has no reader, so a
            // gateway whose every event is being refused looks exactly like a
            // gateway that has sent nothing. This puts it on the screen an
            // owner is already told to check when payments do not arrive.
            //
            // Throttled, and never for 405: this route is public and
            // unauthenticated, so a row per rejection lets anyone flush the
            // owner's real errors out of a bounded log by posting junk. A burst
            // of refusals is one fact, and one row states it.
            if ($outcome->http_status !== 405 && ! get_transient(self::REJECT_NOTICE_KEY . $gatewayId)) {
                set_transient(self::REJECT_NOTICE_KEY . $gatewayId, 1, self::REJECT_NOTICE_TTL);
                ErrorLog::record(
                    'webhook.' . $gatewayId,
                    $outcome->error ?? __('Signature verification failed. The webhook will keep being rejected until the gateway credentials and webhook id match this site.', 'dono'),
                    ['gateway' => $gatewayId, 'event_type' => $outcome->event_type ?? 'unknown']
                );
            }

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

        // A rejected delivery has no event id to dedup on, and (gateway, '')
        // is UNIQUE: without a synthetic id the first refusal is kept and every
        // one after it is silently dropped as a duplicate, which is the exact
        // opposite of what a run of refusals should look like.
        $externalId = (string) ($outcome->external_id ?? '');
        if ($externalId === '') {
            $externalId = 'unverified-' . substr(
                hash('sha256', $gateway . '|' . $request->get_body() . '|' . $this->clock->now()->format('Y-m-d H:i:s.u')),
                0,
                32
            );
        }

        $log = WebhookLog::make();
        $log->gateway      = $gateway;
        $log->external_id  = $externalId;
        $log->event_type   = $outcome->event_type ?? 'unknown';
        $log->signature_ok = $outcome->signature_ok;
        $log->payload      = (string) $request->get_body();
        $log->headers      = $headers;
        $log->processed    = $outcome->handled;
        $log->processed_at = $outcome->handled ? $this->clock->now()->format('Y-m-d H:i:s') : null;
        $log->error        = $outcome->error;
        $log->received_at  = $this->clock->now()->format('Y-m-d H:i:s');

        // Dedup via the UNIQUE index: Queryable throws QueryException on the
        // expected "Duplicate entry" for a redelivered event, which supersedes
        // the row it collided with. Logging must never fail the webhook
        // response (a 5xx makes the gateway retry forever), so non-duplicate
        // insert failures are recorded to the error log and swallowed.
        $prevSuppress = $wpdb ? $wpdb->suppress_errors(true) : false;
        try {
            $log->save();
        } catch (QueryException $e) {
            // Cleared before anything else touches the database: Queryable
            // raises on a non-empty last_error, so the next query would fail
            // carrying this message.
            if ($wpdb) {
                $wpdb->last_error = '';
            }

            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                $this->supersedeDelivery($log);
            } else {
                ErrorLog::record('webhook.log', $e->getMessage(), ['gateway' => $gateway]);
            }
        } finally {
            if ($wpdb) {
                $wpdb->suppress_errors($prevSuppress);
            }
        }
    }

    /**
     * Writes a redelivery over the attempt it duplicates. A gateway resends
     * until it gets a 2xx, so the newest attempt is the one that says what
     * happened to the event, and the row is the only record of it.
     *
     * @since 1.0.0
     */
    private function supersedeDelivery(WebhookLog $log): void
    {
        global $wpdb;

        try {
            $existing = WebhookLog::query()
                ->select('id')
                ->where('gateway', $log->gateway)
                ->where('external_id', $log->external_id)
                ->get();

            $existingId = (int) ($existing->id ?? 0);
            if ($existingId === 0) {
                return;
            }

            $log->id = $existingId;
            $log->save();
        } catch (\Throwable $e) {
            if ($wpdb) {
                $wpdb->last_error = '';
            }

            try {
                ErrorLog::record('webhook.log', $e->getMessage(), ['gateway' => $log->gateway]);
            } catch (\Throwable) {
                // Nothing may escape logging: dispatch() runs it outside its
                // own try, and a 5xx keeps the gateway retrying. ErrorLog has
                // written the message to error_log before its own row write.
            }
        }
    }

}
