<?php

declare(strict_types=1);

namespace Gratora\Donations;

use Gratora\Analytics\DonationAudit;
use Gratora\Analytics\Event;
use Gratora\Foundation\Time\Clock;
use Gratora\Gateways\ChargeLock;
use Gratora\Gateways\CloseUnsettledResult;
use Gratora\Recurring\FrequencyMap;
use Gratora\Vendor\Queryable\DB;
use InvalidArgumentException;

defined('ABSPATH') || exit;

/**
 * Remove a donation and everything describing it.
 *
 * A row that took money can go once its receipt is voided, which a refund
 * does, so the figures it fed are recomputed here rather than left describing
 * something that no longer exists. A row that never took money feeds nothing
 * and skips that work.
 *
 * The reference counter is never rolled back. The audit row carries the
 * reference so the gap in the numbering can be explained afterwards.
 *
 * @since 1.0.0
 */
final class DonationDeleter
{
    /** @since 1.0.0 */
    public function __construct(
        private DonationTrasher $rules,
        private Clock $clock,
        private AggregateSyncer $aggregates,
    ) {
    }

    /**
     * Why each of these rows cannot be permanently deleted, or null where it
     * can. Wider than the trash rules: see DonationTrasher::undeletableReasons.
     *
     * @param list<Donation> $donations
     * @return array<int, ?string> keyed by donation id
     *
     * @since 1.0.0
     */
    public function undeletableReasons(array $donations): array
    {
        return $this->rules->undeletableReasons($donations);
    }

    /**
     * @param bool $requireTrashed Permanent delete from the admin is offered only
     *   from the Trash view, so a row that has left the trash has left the
     *   ceremony that authorised removing it. The donor cascade passes false:
     *   it legitimately removes donations that were never trashed, and a hard
     *   precondition here would abort every ordinary donor delete silently,
     *   because the test-data purge swallows the throw and carries on.
     *
     * @param bool $cascade The caller owns the batch: it has already closed the
     *   payment and already announced the whole batch on the purge hook. Both
     *   are skipped here rather than repeated, because a second single-row
     *   announcement makes a listener reasoning about the batch reach a
     *   different answer than it did the first time.
     *
     * @throws InvalidArgumentException When the row may not be removed.
     *
     * @since 1.0.0
     */
    public function delete(Donation $donation, ?string $note = null, bool $requireTrashed = true, bool $cascade = false): void
    {
        $id = (int) $donation->id;

        // Through the bin where a bin exists. A row that can be trashed has to
        // be, because trashing is what stops its payment, and removing it
        // without that leaves a reference a donor could still pay by hand. A
        // settled row has nothing left to stop and no bin to pass through, so
        // the typed confirmation is the whole of its ceremony.
        if ($requireTrashed
            && $donation->trashed_at === null
            && ($this->rules->untrashableReasons([$donation])[$id] ?? null) === null) {
            throw new InvalidArgumentException(esc_html__('Move this donation to the trash before deleting it permanently.', 'gratora-donation-platform'));
        }

        $reason = $this->undeletableReasons([$donation])[$id] ?? null;
        if ($reason !== null) {
            throw new InvalidArgumentException(esc_html($reason));
        }

        $carriedMoney = self::carriedMoney($donation);

        if (! $cascade) {
            $this->closeFor($donation);
        }

        $snapshot = $this->snapshot($donation);
        $deleted  = false;

        DB::transaction(function () use ($id, $note, $requireTrashed, $cascade, $snapshot, &$deleted): void {
            if (! $this->lockRow($id)) {
                return;
            }

            $locked = Donation::query()->where('id', $id)->get();
            if (! $locked instanceof Donation) {
                return;
            }

            // Trash widens the gap between the close and the delete from
            // seconds to weeks, so re-running the rules here matters more than
            // it would on an immediate action, not less.
            if ($requireTrashed
                && $locked->trashed_at === null
                && ($this->rules->untrashableReasons([$locked])[$id] ?? null) === null) {
                throw new InvalidArgumentException(esc_html__('This donation left the trash while you were looking at it.', 'gratora-donation-platform'));
            }
            if ($this->undeletableReasons([$locked])[$id] !== null) {
                throw new InvalidArgumentException(esc_html__('This donation changed while it was being deleted.', 'gratora-donation-platform'));
            }

            // Add-ons hang their own rows off a donation. A listener that
            // throws aborts this transaction, which is safe here and only here:
            // the maintenance callers of this same hook run no transaction.
            if (! $cascade) {
                do_action('gratora.test_data.purge_donations', [$id]);
            }

            // The cascade path never trashed anything, so the parent it
            // supersedes is still hidden behind a marker naming this row.
            $this->clearRetryMarkers((string) $locked->reference);

            // Append-only, so the donor's previous answer becomes current again
            // rather than a new row being written on their behalf.
            DB::table('gratora_consents')->where('source_donation_id', $id)->delete();

            DB::table('gratora_events')
                ->where('donation_id', $id)
                ->whereNotIn('type', DonationAudit::TYPES)
                ->delete();

            DB::table('gratora_donation_notes')->where('donation_id', $id)->delete();

            // Read before they go. The reference counter is never rolled back,
            // so the sequence keeps a gap either way, and this audit row is the
            // only thing left that can name the number: the receipt.issued
            // event that carried the id is destroyed by the sweep above.
            $documents = $this->documentsOf($id);

            // A row keyed to an id nothing answers to is unreachable from every
            // screen and is dropped outright by the importer, so leaving it
            // records nothing. The donor cascade already removed refunds, so
            // the two delete paths disagreed about this until now.
            DB::table('gratora_receipts')->where('donation_id', $id)->delete();
            DB::table('gratora_refunds')->where('donation_id', $id)->delete();

            // Before the row goes, and never through EventRecorder: a failed
            // insert has to abort the delete rather than be swallowed, or the
            // donation is gone with nothing saying who removed it.
            $this->recordAudit($locked, $note, $snapshot['consent_ids'], $documents);

            Donation::query()->where('id', $id)->delete();

            $deleted = true;
        });

        if (! $deleted) {
            return;
        }

        if ($carriedMoney) {
            $this->recompute($snapshot);
        }

        do_action('gratora.donation.deleted', $snapshot);
    }

    /**
     * Make sure nothing can still be charged against this row.
     *
     * Where trash already closed the handle, the gateway is not asked again:
     * re-closing draws exactly the refusal this treats as fatal, which would
     * turn a successful trash into a permanent barrier to deleting the row. The
     * charge lock is taken instead, because a lock expires and a new charge can
     * be in flight weeks after the trash.
     *
     * Where it was not, the close runs and the gateway's own seam holds the
     * lock across it. Claiming here as well would make this refuse its own
     * gateway call.
     *
     * Public so a caller deleting a batch can do this for every row before it
     * opens a transaction, rather than holding row locks across the network.
     *
     * @throws InvalidArgumentException When the payment cannot be closed.
     *
     * @since 1.0.0
     */
    /**
     * Whether this row is one a total was ever built from, which decides both
     * that there is no payment left to close and that a stored total has to be
     * recomputed once it goes.
     *
     * @since 1.0.0
     */
    private static function carriedMoney(Donation $donation): bool
    {
        return $donation->paid_at !== null
            || in_array((string) $donation->status, ['paid', 'partial_refund', 'refunded', 'disputed'], true);
    }

    public function closeFor(Donation $donation): void
    {
        // Nothing is in flight on a row that already settled, so there is
        // nothing to ask for. Asking anyway is meaningless, and on a gateway
        // this site holds no credentials for it comes back a refusal about
        // stopping a payment that finished months ago, which would block the
        // delete on the money having moved: the thing the caller accepted.
        if (self::carriedMoney($donation)) {
            return;
        }

        if ($donation->payment_stopped_at !== null) {
            $lock = new ChargeLock((string) $donation->gateway);
            if (! $lock->claim($donation)) {
                throw new InvalidArgumentException(esc_html__('Another admin is acting on this donation. Try again in a moment.', 'gratora-donation-platform'));
            }
            $lock->release($donation);

            return;
        }

        $close = $this->rules->closePaymentFor($donation);

        if ($close->outcome === CloseUnsettledResult::LOCKED) {
            throw new InvalidArgumentException(esc_html__('Another admin is acting on this donation. Try again in a moment.', 'gratora-donation-platform'));
        }

        if (! $close->isClosed()) {
            throw new InvalidArgumentException(esc_html((string) $close->reason));
        }
    }

    /**
     * Everything a subscriber needs once the row itself is gone.
     *
     * @return array<string,mixed>
     */
    /**
     * The documents a delete is about to remove, named so the gap they leave
     * can be explained afterwards.
     *
     * @return array{receipts:list<array<string,mixed>>,refunds:list<array<string,mixed>>}
     */
    private function documentsOf(int $donationId): array
    {
        $receipts = [];
        foreach (DB::table('gratora_receipts')->where('donation_id', $donationId)->getAll() as $row) {
            $r          = (array) $row;
            $receipts[] = [
                'receipt_number' => (string) ($r['receipt_number'] ?? ''),
                'renderer_id'    => (string) ($r['renderer_id'] ?? ''),
                'voided'         => (bool) ($r['voided'] ?? false),
                'issued_at'      => (string) ($r['issued_at'] ?? ''),
            ];
        }

        $refunds = [];
        foreach (DB::table('gratora_refunds')->where('donation_id', $donationId)->getAll() as $row) {
            $r         = (array) $row;
            $refunds[] = [
                'amount_cents'      => (int) ($r['amount_cents'] ?? 0),
                'currency'          => (string) ($r['currency'] ?? ''),
                'status'            => (string) ($r['status'] ?? ''),
                'gateway_refund_id' => (string) ($r['gateway_refund_id'] ?? ''),
                'occurred_at'       => (string) ($r['occurred_at'] ?? ''),
            ];
        }

        return ['receipts' => $receipts, 'refunds' => $refunds];
    }

    /**
     * Put the stored totals back in step with the rows that remain.
     *
     * @param array<string,mixed> $snapshot
     */
    private function recompute(array $snapshot): void
    {
        if ((int) $snapshot['donor_id'] > 0) {
            $this->aggregates->syncDonor((int) $snapshot['donor_id']);
        }
        if ($snapshot['campaign_id'] !== null) {
            $this->aggregates->syncCampaign((int) $snapshot['campaign_id']);
        }
        if ($snapshot['fund_id'] !== null) {
            $this->aggregates->syncFund((int) $snapshot['fund_id']);
        }
        if ($snapshot['form_id'] !== null) {
            $this->aggregates->syncForm((int) $snapshot['form_id']);
        }
        if ($snapshot['recurring_plan_id'] !== null) {
            $this->aggregates->syncRecurringPlan((int) $snapshot['recurring_plan_id']);
        }
    }

    private function snapshot(Donation $donation): array
    {
        $id = (int) $donation->id;

        $consentIds = array_values(array_map(
            static fn ($row): int => (int) ((array) $row)['id'],
            DB::table('gratora_consents')->select('id')->where('source_donation_id', $id)->getAll()
        ));

        return [
            'id'                 => $id,
            'reference'          => (string) $donation->reference,
            'donor_id'           => (int) $donation->donor_id,
            'campaign_id'        => $donation->campaign_id !== null ? (int) $donation->campaign_id : null,
            'form_id'            => $donation->form_id !== null ? (int) $donation->form_id : null,
            'fund_id'            => $donation->fund_id !== null ? (int) $donation->fund_id : null,
            'fundraiser_id'      => $donation->fundraiser_id !== null ? (int) $donation->fundraiser_id : null,
            'fundraiser_team_id' => $donation->fundraiser_team_id !== null ? (int) $donation->fundraiser_team_id : null,
            'kind'               => (string) $donation->kind,
            'gateway'            => (string) $donation->gateway,
            'is_test'            => (bool) $donation->is_test,
            'recurring_plan_id'  => $donation->recurring_plan_id !== null ? (int) $donation->recurring_plan_id : null,
            'consent_ids'        => $consentIds,
        ];
    }

    /** @see AggregateSyncer::lockRow, which documents why a plain read will not do. */
    private function lockRow(int $id): bool
    {
        $prefix = DB::getPrefix();
        $result = DB::raw("SELECT id FROM {$prefix}gratora_donations WHERE id = %d FOR UPDATE", [$id]);

        return isset($result['rows'][0]);
    }

    /** The parent this row superseded belongs back in the list once it is gone. */
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
     * donor_id stays null, or an admin's action lands on the donor's own
     * activity timeline as something the donor did.
     *
     * @param list<int>                  $consentIds
     * @param array{receipts:list<array<string,mixed>>,refunds:list<array<string,mixed>>} $documents
     */
    private function recordAudit(Donation $donation, ?string $note, array $consentIds, array $documents): void
    {
        $user = wp_get_current_user();

        $payload = [
            'reference'   => (string) $donation->reference,
            'status'      => (string) $donation->status,
            'kind'        => (string) $donation->kind,
            'gateway'     => (string) $donation->gateway,
            'fund_id'     => $donation->fund_id !== null ? (int) $donation->fund_id : null,
            'created_at'  => (string) $donation->created_at,
            'actor_name'  => (string) ($user->display_name ?? ''),
            'consent_ids' => $consentIds,
            // What an auditor can still ask about after the rows are gone.
            'receipts'    => $documents['receipts'],
            'refunds'     => $documents['refunds'],
        ];

        // A subscription payment was the site's only trace of the mandate, so
        // the trace moves here rather than being the reason the row can never
        // go. It names the handle somebody would need at the processor.
        if (FrequencyMap::isRecurring((string) $donation->frequency)) {
            $payload['subscription'] = [
                'frequency'         => (string) $donation->frequency,
                'recurring_plan_id' => $donation->recurring_plan_id !== null ? (int) $donation->recurring_plan_id : null,
                'gateway_txn_id'    => $donation->gateway_txn_id !== null ? (string) $donation->gateway_txn_id : null,
                'gateway_intent_id' => $donation->gateway_intent_id !== null ? (string) $donation->gateway_intent_id : null,
            ];
        }

        $trimmed = is_string($note) ? trim($note) : '';
        if ($trimmed !== '') {
            $payload['note'] = mb_substr($trimmed, 0, 500);
        }

        $e               = Event::make();
        $e->type         = 'donation.deleted';
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
