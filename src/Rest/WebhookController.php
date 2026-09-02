<?php

declare(strict_types=1);

namespace FundKit\Rest;

use FundKit\Analytics\ErrorLog;
use FundKit\Donations\AntiSpamGuard;
use FundKit\Analytics\Event;
use FundKit\Analytics\EventRecorder;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\WebhookOutcome;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Incoming-webhook dispatcher: POST /fundkit/v1/webhooks/{gateway}; handleWebhook()
 * verifies the signature. Every delivery is recorded to the log the site owner
 * reads, whether or not anything came of it.
 *
 * @since 1.0.0
 */
final class WebhookController
{
    private const NAMESPACE = 'fundkit/v1';

    /**
     * Signature failures one address may spend on one gateway before this
     * route stops doing the work of refusing it.
     *
     * ONLY failures are counted, which is what makes this safe: a gateway
     * whose signatures verify never accumulates one, so a real event is never
     * turned away. Dropping a payment_intent.succeeded would leave money that
     * moved unrecorded, and no rate limit is worth that.
     *
     * It is worth counting because refusing is not free. PayPal verifies by
     * calling PayPal, and tries live then test, so an unauthenticated POST
     * carrying nothing but junk costs this site up to two blocking outbound
     * requests before anything has been authenticated at all.
     *
     * A site with the wrong secret configured trips this, and that is the
     * right outcome: those events were already being rejected, and 429 tells
     * the gateway to back off and redeliver rather than to keep hammering.
     */
    private const FAIL_MAX    = 10;
    private const FAIL_WINDOW = 900;

    /** One recorded refusal per gateway per window. */
    private const FAIL_KEY          = 'fundkit_wh_fail_';
    private const REJECT_NOTICE_KEY = 'fundkit_webhook_rejected_';
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
        private AntiSpamGuard $spam,
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
            return new WP_Error('fundkit_unknown_gateway', sprintf(__('Unknown gateway: %s', 'fundraising-toolkit'), $gatewayId), ['status' => 404]);
        }

        // Asked before the handler, because the handler is the expensive part.
        $failKey = $this->spam->subjectKey(self::FAIL_KEY . $gatewayId);
        if ($this->spam->peek($failKey, self::FAIL_WINDOW) >= self::FAIL_MAX) {
            return new WP_Error(
                'fundkit_webhook_rejected',
                __('Too many rejected deliveries. Please try again shortly.', 'fundraising-toolkit'),
                ['status' => 429]
            );
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

        // Counted after the fact and only for a refusal, so a verifying
        // gateway never spends one.
        if (! $outcome->signature_ok) {
            $this->spam->hit($failKey, self::FAIL_WINDOW);
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
                    $outcome->error ?? __('Signature verification failed. The webhook will keep being rejected until the gateway credentials and webhook id match this site.', 'fundraising-toolkit'),
                    ['gateway' => $gatewayId, 'event_type' => $outcome->event_type ?? 'unknown']
                );
            }

            return new WP_Error(
                'fundkit_webhook_rejected',
                $outcome->error ?? __('Webhook rejected.', 'fundraising-toolkit'),
                ['status' => $outcome->http_status]
            );
        }

        if ($outcome->error !== null && $outcome->http_status >= 500) {
            return new WP_Error(
                'fundkit_webhook_error',
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
            ErrorLog::toDebugLog(sprintf('webhook delivery log failed (%s): %s', $gateway, $e->getMessage()));
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
