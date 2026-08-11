<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Analytics\EventRecorder;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\WebhookOutcome;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Incoming-webhook dispatcher: POST /dono/v1/webhooks/{gateway}; handleWebhook()
 * verifies the signature. Every delivery is recorded to the log the site owner
 * reads, whether or not anything came of it.
 *
 * @since 1.0.0
 */
final class WebhookController
{
    private const NAMESPACE = 'dono/v1';

    /** One recorded refusal per gateway per window. */
    private const REJECT_NOTICE_KEY = 'dono_webhook_rejected_';
    private const REJECT_NOTICE_TTL = 15 * MINUTE_IN_SECONDS;

    /** Log family these rows belong to, one type per gateway beneath it. */
    private const TYPE_PREFIX = 'webhook.';

    /**
     * Newest deliveries kept.
     *
     * Counted within the family, so the two logs cannot evict one another: a
     * gateway retrying every minute leaves the errors that explain the retries
     * where the owner can still read them. A count rather than a window of
     * days, because a retry loop produces a week of rows in an afternoon, and a
     * larger one than the errors get because a site taking donations all day
     * takes far more deliveries than it does errors.
     */
    private const KEEP = 2000;

    /** Overhang tolerated before pruning, so the delete runs once per SLACK deliveries. */
    private const SLACK = 200;

    /** @since 1.0.0 */
    public function __construct(
        private GatewayManager $gateways,
        private EventRecorder $events,
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

        $this->logDelivery($gatewayId, $outcome);

        if (! $outcome->signature_ok && $outcome->http_status >= 400) {
            // Also recorded as an error, throttled and never for 405: this
            // route is public and unauthenticated, so anyone can post junk
            // until the delivery rows hit their cap and roll over. The error
            // family has its own cap, which is what keeps a gateway whose every
            // event is being refused legible underneath that. A burst of
            // refusals is one fact, and one row states it.
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

    /**
     * One row per delivery. A gateway resends until it gets a 2xx, so a
     * redelivery is a delivery of its own and is recorded as one: the log shows
     * how many attempts an event took rather than only the last of them.
     *
     * @since 1.0.0
     */
    private function logDelivery(string $gateway, WebhookOutcome $outcome): void
    {
        try {
            $this->events->record(self::TYPE_PREFIX . $gateway, [
                'payload' => [
                    'event_type' => ((string) $outcome->event_type) ?: 'unknown',
                    'verified'   => $outcome->signature_ok,
                    'processed'  => $outcome->handled,
                    'error'      => $outcome->error,
                ],
            ]);

            $this->prune();
        } catch (\Throwable $e) {
            // A 5xx makes the gateway retry forever, so nothing about recording
            // the delivery may reach the response.
            error_log(sprintf('[dono] webhook delivery log failed (%s): %s', $gateway, $e->getMessage()));
        }
    }

    /**
     * Drop everything past the newest KEEP deliveries. The count runs on every
     * write, over an index and a set bounded by KEEP + SLACK, so it stays cheap
     * precisely when deliveries are arriving fast.
     *
     * @since 1.0.0
     */
    private function prune(): void
    {
        $total = (int) Event::query()->whereLike('type', self::TYPE_PREFIX . '%')->count();
        if ($total <= self::KEEP + self::SLACK) {
            return;
        }

        // The id of the oldest delivery worth keeping. Deleting by id beats an
        // OFFSET delete, which MySQL does not allow.
        $oldestKept = Event::query()
            ->whereLike('type', self::TYPE_PREFIX . '%')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->offset(self::KEEP - 1)
            ->get();

        if (! $oldestKept) {
            return;
        }

        Event::query()
            ->whereLike('type', self::TYPE_PREFIX . '%')
            ->where('id', (int) $oldestKept->id, '<')
            ->delete();
    }
}
