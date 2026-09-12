<?php

declare(strict_types=1);

namespace Gratora\Donations;

use Gratora\Analytics\Event;
use Gratora\Foundation\Maintenance\AbandonedPendingReaper;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\ClosesUnsettledPayment;
use Gratora\Gateways\CloseUnsettledResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\SettlesOutOfBand;
use Gratora\Recurring\FrequencyMap;
use Gratora\Vendor\Queryable\DB;

defined('ABSPATH') || exit;

/**
 * Take a spam attempt off the working list, and stop whatever the gateway is
 * still holding open for it.
 *
 * One eligibility set, shared with permanent delete, which is what keeps the
 * invariant everything else rests on: no trashed row ever moved money. Because
 * of it no total, report, receipt, statement or currency lock needs a trash
 * predicate or a recalculation.
 *
 * The charge lock is deliberately not claimed here. Each gateway's
 * closeUnsettled owns its own lease, and claiming the same key first would make
 * this refuse its own gateway call.
 *
 * @since 1.0.0
 */
final class DonationTrasher
{
    /** Statuses that can never reach the trash, with the reason each is refused. */
    private const SETTLED = ['paid', 'partial_refund'];
    private const FINAL   = ['refunded', 'disputed'];

    /** @since 1.0.0 */
    public function __construct(
        private GatewayManager $gateways,
        private Clock $clock,
    ) {
    }

    /**
     * Why each of these rows cannot be trashed, or null where it can.
     *
     * Local checks only: rules 1 to 7, plus whether rule 8's age fallback would
     * apply. Nothing here calls a gateway, so a list of fifty rows costs two
     * queries rather than fifty network round trips.
     *
     * @param list<Donation> $donations
     * @return array<int, ?string> keyed by donation id
     *
     * @since 1.0.0
     */
    public function untrashableReasons(array $donations): array
    {
        return $this->eligibilityReasons($donations, 'gratora.donation.untrashable_reason');
    }

    /**
     * The one rule set, asked under whichever name the caller answers to.
     *
     * Trash accepts exactly the rows permanent delete accepts. Two rule sets
     * would drift, and the drift would be a row that can be hidden but never
     * removed, or removed without ever having been stopped.
     *
     * @param list<Donation> $donations
     * @return array<int, ?string> keyed by donation id
     *
     * @since 1.0.0
     */
    public function eligibilityReasons(array $donations, string $filterHook): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (Donation $d): int => (int) $d->id,
            $donations
        )));

        $withReceipt = $this->idsPresentIn('gratora_receipts', $ids);
        $withRefund  = $this->idsPresentIn('gratora_refunds', $ids);

        $out = [];
        foreach ($donations as $donation) {
            $out[(int) $donation->id] = $this->localReason($donation, $withReceipt, $withRefund, $filterHook);
        }

        return $out;
    }

    /**
     * @param array<int,true> $withReceipt
     * @param array<int,true> $withRefund
     */
    private function localReason(Donation $donation, array $withReceipt, array $withRefund, string $filterHook): ?string
    {
        $id     = (int) $donation->id;
        $status = (string) $donation->status;

        if ((string) $donation->kind !== 'donation') {
            return __('This is a ticket order payment. Manage it from the order.', 'gratora-donation-platform');
        }

        if ($donation->recurring_plan_id !== null) {
            return __('This donation belongs to a recurring plan. Cancel the plan first.', 'gratora-donation-platform');
        }

        // PayPal takes the first payment the moment the donor approves, and with
        // the row gone nothing on the site can show or cancel the subscription.
        if ((string) $donation->gateway === 'paypal'
            && FrequencyMap::isRecurring((string) $donation->frequency)) {
            return __('This is a PayPal recurring signup. Cancel it at PayPal first.', 'gratora-donation-platform');
        }
        if (str_starts_with((string) ($donation->gateway_intent_id ?? ''), 'pending_subscription_')) {
            return __('This is a recurring signup that has not finished. Cancel it at the gateway first.', 'gratora-donation-platform');
        }

        if (in_array($status, self::SETTLED, true)) {
            return __('This donation was paid. Refund it instead.', 'gratora-donation-platform');
        }

        if (in_array($status, self::FINAL, true)) {
            return __('This donation is the record of money that moved, so it stays.', 'gratora-donation-platform');
        }

        if ($status === 'processing') {
            return __('This payment is still settling and can still arrive.', 'gratora-donation-platform');
        }

        if (! in_array($status, ['pending', 'failed'], true)) {
            /* translators: %s: the donation's current status. */
            return sprintf(__('A %s donation cannot be moved to the trash.', 'gratora-donation-platform'), $status);
        }

        // Anything that saw money leaves one of these behind, even on a row
        // that never reached paid.
        if ($donation->paid_at !== null
            || (string) ($donation->gateway_txn_id ?? '') !== ''
            || (int) $donation->refunded_cents > 0
            || isset($withReceipt[$id])
            || isset($withRefund[$id])) {
            return __('Something was recorded against this donation, so it is a reconciliation question rather than litter.', 'gratora-donation-platform');
        }

        /** @var ?string $filtered */
        $filtered = apply_filters($filterHook, null, $donation);

        return is_string($filtered) && $filtered !== '' ? $filtered : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,true>
     */
    private function idsPresentIn(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (DB::table($table)->select('donation_id')->whereIn('donation_id', $ids)->getAll() as $row) {
            $donationId = (int) ((array) $row)['donation_id'];
            if ($donationId > 0) {
                $out[$donationId] = true;
            }
        }

        return $out;
    }

    /**
     * Stop the payment, then take the row off the list.
     *
     * In that order, and never the reverse: a row hidden from the list while its
     * charge is still open is the failure this whole feature exists to prevent.
     *
     * @since 1.0.0
     */
    public function trash(Donation $donation, ?string $reasonNote = null): TrashOutcome
    {
        if ($donation->trashed_at !== null) {
            return TrashOutcome::already();
        }

        $reason = $this->untrashableReasons([$donation])[(int) $donation->id] ?? null;
        if ($reason !== null) {
            return TrashOutcome::refused($reason);
        }

        $close = $this->closePaymentFor($donation);
        if ($close->outcome === CloseUnsettledResult::LOCKED) {
            return TrashOutcome::locked();
        }
        if (! $close->isClosed()) {
            return TrashOutcome::refused((string) $close->reason);
        }

        $stoppedReason = $this->stopReasonFor($donation);
        $now           = $this->clock->now()->format('Y-m-d H:i:s');
        $actor         = get_current_user_id();
        $applied       = 0;

        DB::transaction(function () use ($donation, $now, $actor, $stoppedReason, $reasonNote, &$applied): void {
            // Claim the row before re-reading it: between the gateway call and
            // this write it may have settled, and a plain read inside the
            // transaction is served from its own snapshot.
            if (! $this->lockRow((int) $donation->id)) {
                return;
            }

            $locked = Donation::query()->where('id', (int) $donation->id)->get();
            if (! $locked instanceof Donation) {
                return;
            }

            // The rules again, on the row as it stands now. The gap between the
            // close and this write is where a late webhook lands.
            if ($this->untrashableReasons([$locked])[(int) $locked->id] !== null) {
                return;
            }

            $applied = Donation::query()
                ->where('id', (int) $donation->id)
                ->whereIsNull('trashed_at')
                ->update([
                    'trashed_at'             => $now,
                    'trashed_by'             => $actor,
                    'payment_stopped_at'     => $now,
                    'payment_stopped_reason' => $stoppedReason,
                    'updated_at'             => $now,
                ])
                ->affectedRows;

            if ($applied < 1) {
                return;
            }

            $donation->trashed_at             = $now;
            $donation->trashed_by             = $actor;
            $donation->payment_stopped_at     = $now;
            $donation->payment_stopped_reason = $stoppedReason;

            // A parent whose retry this was is superseded, which hides it from
            // every screen. With the child in the bin it would be in none at
            // all, still payable and unreachable.
            $this->clearRetryMarkers((string) $donation->reference);

            $this->recordAudit('donation.trashed', $donation, $reasonNote);
        });

        if ($applied < 1) {
            return TrashOutcome::refused(
                __('This donation changed while it was being stopped. Reload the list and try again.', 'gratora-donation-platform')
            );
        }

        do_action('gratora.donation.trashed', $donation);

        return TrashOutcome::trashed($stoppedReason !== self::NOTHING_OPEN);
    }

    /** The reason recorded where there was never anything at the gateway to close. */
    private const NOTHING_OPEN = 'nothing_was_open_to_close';

    /**
     * Put the record back, exactly as it was.
     *
     * payment_stopped_at is deliberately left alone, which is what lets the
     * restored row say its payment is still stopped without a field of its own.
     *
     * @since 1.0.0
     */
    public function restore(Donation $donation): void
    {
        $applied = Donation::query()
            ->where('id', (int) $donation->id)
            ->whereIsNotNull('trashed_at')
            ->update([
                'trashed_at' => null,
                'trashed_by' => null,
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->affectedRows;

        // Only the request that actually moved it records or announces
        // anything, so a double click is one audit row and one hook.
        if ($applied < 1) {
            return;
        }

        $donation->trashed_at = null;
        $donation->trashed_by = null;

        $this->recordAudit('donation.restored', $donation, null);

        do_action('gratora.donation.restored', $donation);
    }

    /**
     * Ask the gateway to close whatever it is still holding open.
     *
     * Three shapes: a gateway that settles out of band has nothing open, one
     * implementing the seam answers for itself, and anything else falls back on
     * the age rule that also covers a gateway this site no longer has.
     */
    public function closePaymentFor(Donation $donation): CloseUnsettledResult
    {
        $gateway = $this->gateways->get((string) $donation->gateway);

        if ($gateway instanceof SettlesOutOfBand) {
            return CloseUnsettledResult::closed();
        }

        if ($gateway instanceof ClosesUnsettledPayment) {
            $result = $gateway->closeUnsettled($donation);

            // An unreachable gateway told us nothing, so the age rule may still
            // admit the row. A refusal is an answer and does not get a second
            // chance at one.
            if (! $result->isClosed() && $result->mayFallBackOnAge() && $this->oldEnoughToAbandon($donation)) {
                return CloseUnsettledResult::closed();
            }

            return $result;
        }

        if ($this->oldEnoughToAbandon($donation)) {
            return CloseUnsettledResult::closed();
        }

        return CloseUnsettledResult::refused(sprintf(
            /* translators: %s: the payment gateway's id, for example "razorpay". */
            __('Gratora cannot ask %s to stop this payment, so it can only be trashed once it is old enough to have been abandoned.', 'gratora-donation-platform'),
            (string) $donation->gateway
        ));
    }

    /**
     * The fallback: a failed row old enough that nothing is coming. Measured
     * from created_at, never from the trash.
     */
    private function oldEnoughToAbandon(Donation $donation): bool
    {
        if ((string) $donation->status !== 'failed') {
            return false;
        }

        $cutoff = $this->clock->now()
            ->modify('-' . AbandonedPendingReaper::abandonAfterDays() . ' days')
            ->format('Y-m-d H:i:s');

        return (string) $donation->created_at < $cutoff;
    }

    private function stopReasonFor(Donation $donation): string
    {
        $gateway = $this->gateways->get((string) $donation->gateway);

        // An out-of-band pledge is the one row the abandon sweep never touches,
        // so without its own stop record the donor gate would hold it forever.
        return $gateway instanceof SettlesOutOfBand
            ? self::NOTHING_OPEN
            : 'closed_at_gateway';
    }

    /** @see AggregateSyncer::lockRow, which documents why a plain read will not do. */
    private function lockRow(int $id): bool
    {
        $prefix = DB::getPrefix();
        $result = DB::raw("SELECT id FROM {$prefix}gratora_donations WHERE id = %d FOR UPDATE", [$id]);

        return isset($result['rows'][0]);
    }

    /**
     * Point every pending parent naming this reference back at nothing.
     *
     * flags is rewritten wholesale by code this does not own, so the key is
     * dropped from the row's own current value rather than from a snapshot, and
     * encoded by hand because the builder writes json columns as given.
     */
    private function clearRetryMarkers(string $childReference): void
    {
        $rows = Donation::query()
            ->where('status', 'pending')
            ->whereRaw(
                "AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(flags), flags, NULL), '\$.retried_by')) = %s",
                $childReference
            )
            ->getAll();

        foreach ($rows as $parent) {
            $flags = (array) ($parent->flags ?? []);
            unset($flags['retried_by']);

            Donation::query()
                ->where('id', (int) $parent->id)
                ->where('status', 'pending')
                ->update([
                    'flags'      => (string) wp_json_encode($flags),
                    'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                ]);
        }
    }

    /**
     * Written with Event::make() inside the transaction, never through
     * EventRecorder, which swallows write failures and runs a rewritable
     * filter. An operation committed with no record is the thing this rules out.
     *
     * donor_id stays null, or an admin's action lands on the donor's own
     * activity timeline as something the donor did.
     */
    private function recordAudit(string $type, Donation $donation, ?string $reasonNote): void
    {
        $user = wp_get_current_user();

        $payload = [
            'reference'  => (string) $donation->reference,
            'status'     => (string) $donation->status,
            'kind'       => (string) $donation->kind,
            'gateway'    => (string) $donation->gateway,
            'fund_id'    => $donation->fund_id !== null ? (int) $donation->fund_id : null,
            'created_at' => (string) $donation->created_at,
            'actor_name' => (string) ($user->display_name ?? ''),
        ];

        $note = is_string($reasonNote) ? trim($reasonNote) : '';
        if ($note !== '') {
            $payload['note'] = mb_substr($note, 0, 500);
        }

        $e               = Event::make();
        $e->type         = $type;
        $e->donation_id  = (int) $donation->id;
        $e->user_id      = get_current_user_id() ?: null;
        $e->campaign_id  = $donation->campaign_id !== null ? (int) $donation->campaign_id : null;
        $e->form_id      = $donation->form_id !== null ? (int) $donation->form_id : null;
        $e->amount_cents = (int) $donation->amount_cents;
        $e->currency     = (string) $donation->currency;
        $e->occurred_at  = $this->clock->now()->format('Y-m-d H:i:s');
        $e->payload      = $payload;
        $e->save();
    }
}
