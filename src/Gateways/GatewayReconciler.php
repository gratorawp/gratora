<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Analytics\ErrorLog;
use Dono\Async\AsyncDispatcher;
use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalMoney;
use Dono\Vendor\Queryable\ModelQueryBuilder;
use Throwable;

/**
 * Asks PayPal what became of donations whose money it may already have.
 *
 * A single verified webhook is the only automatic way out of `processing`, so a
 * delivery that is refused or lost strands real money there with no retry, no
 * poll and nothing on any screen saying so. This is the second path to the same
 * answer, and it reaches one status further back: the request that captures an
 * order can die between PayPal taking the money and the donation being marked
 * paid, which leaves no local trace of the capture at all.
 *
 * It reads the order and never re-POSTs the capture. The capture carries a
 * stable PayPal-Request-Id per donation, so a second POST replays the original
 * response and would report the hold forever regardless of what PayPal has done
 * with the money since.
 *
 * PayPal only for now, because it is the only gateway whose money can be held
 * server-side with no local record of the release.
 *
 * @since 1.0.0
 */
final class GatewayReconciler
{
    public const HOOK = 'dono.cron.gateway_reconcile';

    private const GATEWAY = 'paypal';

    /**
     * The webhook settles a released capture within seconds. This bounds how
     * long a lost delivery can stay invisible instead, and costs at most BATCH
     * reads an hour to do it.
     */
    private const HOURLY = 3600;

    /**
     * One blocking API round trip per donation, taken one after another inside
     * a cron tick shared with every other job.
     */
    private const BATCH = 20;

    /**
     * A far smaller share of the run than held money gets: every abandoned
     * checkout leaves a pending donation carrying an order id, so this set is
     * mostly orders nobody ever approved.
     */
    private const STRANDED_BATCH = 5;

    /**
     * The donor's own request owns the donation while the flow is live. This
     * only looks at what a finished one left behind.
     */
    private const STRANDED_MIN_AGE_MINUTES = 15;

    /**
     * Polling stops here. An eCheck is the longest hold PayPal clears by itself
     * and it takes days, so a payment still held after two weeks is waiting on
     * a person inside the PayPal account and no number of reads will move it.
     */
    private const MAX_AGE_DAYS = 14;

    /**
     * How far into each match set the last run got. A donation PayPal is still
     * holding matches again on the next read, so without a cursor the same
     * first rows fill every run and anything behind them is never reached.
     */
    private const CURSOR_OPTION = 'dono_gateway_reconcile_cursor';

    /** @since 1.0.0 */
    public function __construct(
        private PayPalApi $api,
        private PayPalAccount $account,
        private DonationService $donations,
        private Clock $clock,
        private AsyncDispatcher $async,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::HOURLY));
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        // A bad hour at a third party is ordinary operation, not a broken job.
        // Caught so it lands in the error log with the rest of this gateway's
        // trouble rather than as a failed scheduled action.
        try {
            $this->sweep();
        } catch (Throwable $e) {
            $this->note('Reconciling PayPal donations failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $context
     *
     * @since 1.0.0
     */
    private function note(string $message, array $context = []): void
    {
        try {
            ErrorLog::record('gateway.' . self::GATEWAY, $message, $context);
        } catch (Throwable $e) {
            // ErrorLog writes to error_log before it goes near the database, so
            // the message has already landed. A broken events table must not be
            // what takes the queue down.
        }
    }

    /** @since 1.0.0 */
    private function sweep(): void
    {
        if (! $this->account->isConnected()) {
            return;
        }

        $held     = $this->held();
        $failures = 0;
        $first    = null;

        foreach ($held as $donation) {
            try {
                $this->reconcile($donation);
            } catch (Throwable $e) {
                $failures++;
                $first ??= ['id' => (int) $donation->id, 'message' => $e->getMessage()];
            }
        }

        foreach ($this->stranded() as $donation) {
            try {
                $this->reconcile($donation);
            } catch (Throwable $e) {
                // PayPal purges an order nobody approved, so a read that fails
                // on a pending donation is the ordinary end of an abandoned
                // checkout. Reported, it would be every abandoned checkout on
                // the site, every hour.
                continue;
            }
        }

        if ($first !== null) {
            // One entry per run rather than one per donation: the same
            // unreadable order comes back every hour, and ErrorLog keeps the
            // newest 500, so a single stuck donation could otherwise push every
            // other error off the screen.
            $this->note(
                sprintf(
                    'Could not read %d of %d held donations at PayPal. First: %s',
                    $failures,
                    count($held),
                    $first['message']
                ),
                ['donation_id' => $first['id']]
            );
        }
    }

    /**
     * Money PayPal took or is holding, whose settling webhook never arrived.
     *
     * @return array<int,Donation>
     *
     * @since 1.0.0
     */
    private function held(): array
    {
        return $this->page('held', self::BATCH, fn () => $this->candidates('processing'));
    }

    /**
     * Donations still at pending whose capture may have completed anyway.
     *
     * PayPal answers the capture POST after the money moves, so a request that
     * dies in between leaves the money taken and nothing at all written down.
     * Only a completed capture is acted on: the rest of this set is checkouts
     * that were abandoned or are being retried, which are the donor's to
     * finish.
     *
     * @return array<int,Donation>
     *
     * @since 1.0.0
     */
    private function stranded(): array
    {
        $quiet = $this->clock->now()
            ->modify('-' . self::STRANDED_MIN_AGE_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        return $this->page(
            'stranded',
            self::STRANDED_BATCH,
            fn () => $this->candidates('pending')->where('created_at', $quiet, '<=')
        );
    }

    /**
     * @return ModelQueryBuilder<Donation>
     *
     * @since 1.0.0
     */
    private function candidates(string $status): ModelQueryBuilder
    {
        $cutoff = $this->clock->now()
            ->modify('-' . self::MAX_AGE_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        return Donation::query()
            ->where('status', $status)
            ->where('gateway', self::GATEWAY)
            // Only a one-time donation has an Order to read. A recurring signup
            // carries a placeholder intent id and settles through Subscriptions.
            ->where('frequency', 'one_time')
            // There is nothing to ask PayPal about without an order id, and a
            // row this sweep can never act on must not hold a slot in the run.
            ->whereIsNotNull('gateway_intent_id')
            ->where('gateway_intent_id', '', '!=')
            ->where('created_at', $cutoff, '>=')
            // Oldest first, because the longest wait is the one somebody is
            // asking about. The id breaks ties into a total order, without
            // which paging can read one row twice and step over another.
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * One page of a match set that does not shrink as it is worked.
     *
     * Deliberately not BatchProcessor: a donation PayPal is still holding
     * matches again on the next read, so a full page is no evidence of a
     * backlog and re-enqueueing on one would spin against PayPal until the hold
     * cleared. The cursor advances a page per run instead, which puts every
     * match in front of the sweep within a cycle however long the ones in front
     * of it stay put.
     *
     * @param callable():ModelQueryBuilder<Donation> $build
     *
     * @return array<int,Donation>
     *
     * @since 1.0.0
     */
    private function page(string $key, int $size, callable $build): array
    {
        $offset = $this->cursor($key);

        $rows = $build()->offset($offset)->limit($size)->getAll();

        // Past the end because the rows in front of it resolved: read the start
        // of the next cycle now rather than spend the run on nothing.
        if ($rows === [] && $offset > 0) {
            $offset = 0;
            $rows   = $build()->limit($size)->getAll();
        }

        $this->moveCursor($key, count($rows) < $size ? 0 : $offset + $size);

        return $rows;
    }

    /** @since 1.0.0 */
    private function cursor(string $key): int
    {
        $stored = get_option(self::CURSOR_OPTION, []);

        return is_array($stored) ? max(0, (int) ($stored[$key] ?? 0)) : 0;
    }

    /** @since 1.0.0 */
    private function moveCursor(string $key, int $offset): void
    {
        $stored = get_option(self::CURSOR_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $stored[$key] = $offset;

        update_option(self::CURSOR_OPTION, $stored, false);
    }

    /** @since 1.0.0 */
    private function reconcile(Donation $donation): void
    {
        $orderId = (string) ($donation->gateway_intent_id ?? '');
        if ($orderId === '') {
            return;
        }

        $test = (bool) $donation->is_test;

        // Sandbox and live are separate PayPal accounts, so the other mode's
        // credentials do not read this order wrongly, they do not read it at
        // all. A mode with nothing stored is left alone rather than asked.
        if (! $this->account->hasKeysFor($test)) {
            return;
        }
        $this->account->useTestMode($test);

        $order   = $this->api->get('/v2/checkout/orders/' . rawurlencode($orderId));
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        // An approved order with no capture on it holds no money to resolve.
        if (! is_array($capture)) {
            return;
        }

        $status = strtoupper(trim((string) ($capture['status'] ?? '')));

        // A pending donation is one whose own flow never reported an end, so
        // the single thing worth concluding from its order is that PayPal took
        // the money. Everything else about it belongs to the donor's request:
        // a decline there is an attempt they may be retrying, and a hold is
        // what moves a donation to processing in the first place.
        if ((string) $donation->status !== 'processing') {
            if ($status === 'COMPLETED') {
                $this->settle($donation, $order, $capture);
            }

            return;
        }

        match ($status) {
            'COMPLETED' => $this->settle($donation, $order, $capture),
            // PayPal's three spellings for money it did not take.
            'DECLINED', 'DENIED', 'FAILED' => $this->fail($donation),
            'PENDING' => $this->refreshHoldReason($donation, $capture),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $capture
     *
     * @since 1.0.0
     */
    private function settle(Donation $donation, array $order, array $capture): void
    {
        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));

        // The same guard the webhook confirms under. The mode passed is the
        // donation's own because the credentials that read this order were
        // chosen from it, so what this still decides is whether PayPal took the
        // amount the donation is actually for.
        $refusal = WebhookPaymentGuard::refuse(
            $donation,
            self::GATEWAY,
            (bool) $donation->is_test,
            isset($capture['amount']['value'])
                ? PayPalMoney::toStoredCents(
                    (string) $capture['amount']['value'],
                    $currency !== '' ? $currency : (string) $donation->currency
                )
                : null,
            $currency !== '' ? $currency : null,
        );

        if ($refusal !== null) {
            // Said once, against the donation, because refusing changes nothing
            // about the row and the same order comes back every hour. ErrorLog
            // keeps the newest 500 entries, so repeating this is how one
            // mismatched capture empties the error screen of everything else.
            if ($this->remember($donation, 'paypal_settle_refusal', $refusal)) {
                $this->note(
                    'Refused to settle a donation from its PayPal order: ' . $refusal,
                    ['donation_id' => (int) $donation->id]
                );
            }

            return;
        }

        $fee         = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? null;
        $feeCurrency = (string) ($capture['seller_receivable_breakdown']['paypal_fee']['currency_code'] ?? '');

        // Idempotent: confirm() no-ops on an already-paid row, which is what
        // makes a second sweep, or one racing the webhook, free.
        $this->donations->confirm($donation, [
            'gateway_txn_id' => (string) ($capture['id'] ?? ''),
            'payment_method' => 'paypal',
            'fee_cents'      => $fee !== null ? PayPalMoney::toStoredCents((string) $fee, $feeCurrency) : null,
            'metadata'       => [
                'paypal_order_id'   => (string) ($order['id'] ?? ''),
                'paypal_capture_id' => (string) ($capture['id'] ?? ''),
            ],
        ]);
    }

    /** @since 1.0.0 */
    private function fail(Donation $donation): void
    {
        // Word for word what the DENIED webhook stores, so a donation resolved
        // here reads identically to one the delivery resolved. markFailed()
        // refuses to walk a paid or refunded row backwards.
        $this->donations->markFailed($donation, __('PayPal declined the payment.', 'dono-fundraising-platform'));
    }

    /**
     * @param array<string,mixed> $capture
     *
     * @since 1.0.0
     */
    private function refreshHoldReason(Donation $donation, array $capture): void
    {
        $reason = trim((string) ($capture['status_details']['reason'] ?? ''));

        // No reason is PayPal saying nothing this time, not that the hold
        // changed. Overwriting drops the one line the admin screen has to
        // explain the wait.
        if ($reason === '') {
            return;
        }

        $this->remember($donation, 'paypal_pending_reason', $reason);
    }

    /**
     * Keep one fact about this donation in its gateway metadata.
     *
     * @return bool true when the value was not already there and the row was
     *              still in the status it was read at.
     *
     * @since 1.0.0
     */
    private function remember(Donation $donation, string $key, string $value): bool
    {
        $meta = (array) ($donation->gateway_metadata ?? []);
        if ((string) ($meta[$key] ?? '') === $value) {
            return false;
        }

        $meta[$key] = $value;

        $encoded = wp_json_encode($meta);
        if (! is_string($encoded)) {
            return false;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Written here rather than through markProcessing(), which only
        // transitions out of pending and so writes nothing at all for a
        // donation that is already processing.
        //
        // Named columns under the status this row was read at: a webhook can
        // settle it during the API call that precedes this, and Model::save()
        // would put the whole pre-call snapshot back over the top. The builder
        // stringifies values as given, so the JSON column is encoded above.
        $written = (int) Donation::query()
            ->where('id', (int) $donation->id)
            ->where('status', (string) $donation->status)
            ->update([
                'gateway_metadata' => $encoded,
                'updated_at'       => $now,
            ])->affectedRows;

        if ($written < 1) {
            return false;
        }

        $donation->gateway_metadata = $meta;
        $donation->updated_at       = $now;

        return true;
    }
}
