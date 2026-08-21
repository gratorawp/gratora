<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Foundation\Batch\BatchProcessor;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Funds\Fund;
use Dono\Receipts\Receipt;
use Dono\Receipts\ReceiptContext;
use Dono\Receipts\ReceiptIssuer;
use Dono\Receipts\ReceiptRenderer;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;
use Throwable;

/**
 * Rollback claims for the batch tick, the receipt numbering allocation and the
 * recurring renewal counters.
 *
 * The harness pins Queryable's nesting depth to 1 (see IntegrationTestCase), so
 * every product transaction here is a nested one taking a SAVEPOINT inside
 * WP_UnitTestCase's wrapping transaction. Each case asserts both halves: that a
 * write which really does land is undone by the failure, and that the same
 * operation lands whole when nothing throws.
 */
final class ReceiptRecurringTransactionRollbackTest extends IntegrationTestCase
{
    private const APPLY_FAILURE = 'the second item in this batch could not be handled';

    /**
     * BatchProcessor promises a transactional batch so one bad item does not
     * commit half a pass. The item handled before the failure has to go back
     * with it, or a resumable job re-runs over rows it half-moved.
     */
    public function test_an_apply_that_throws_takes_the_whole_batch_back(): void
    {
        $ids = $this->threeFunds('rollback');

        try {
            BatchProcessor::step(
                static fn (int $n): array => $ids,
                $this->stampingThenThrowing('moved'),
                10
            );
            $this->fail('the failure inside $apply did not reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame(self::APPLY_FAILURE, $e->getMessage());
        }

        $this->assertSame(
            [null, null, null],
            $this->codes($ids),
            'the item stamped before the failure stayed stamped, so the batch committed half a pass'
        );
    }

    /**
     * The control for the case above: the same $apply, the same failure, with
     * the batch's transaction turned off. The first item's write lands and
     * stays, which is what makes the assertion above about the transaction and
     * not about a write that never happened.
     */
    public function test_without_the_transaction_the_same_failure_leaves_half_a_pass_behind(): void
    {
        $ids = $this->threeFunds('untransacted');

        try {
            BatchProcessor::step(
                static fn (int $n): array => $ids,
                $this->stampingThenThrowing('moved'),
                10,
                false
            );
            $this->fail('the failure inside $apply did not reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame(self::APPLY_FAILURE, $e->getMessage());
        }

        $this->assertSame(
            ['moved', null, null],
            $this->codes($ids),
            'the write the rolled-back case asserts is gone never landed here either'
        );
    }

    /** A batch that finishes commits every item, not just the ones before the last write. */
    public function test_a_batch_that_finishes_keeps_every_item(): void
    {
        $ids = $this->threeFunds('committed');

        $more = BatchProcessor::step(
            static fn (int $n): array => $ids,
            function (array $batch): void {
                foreach ($batch as $id) {
                    Fund::query()->where('id', $id)->update(['accounting_code' => 'moved']);
                }
            },
            10
        );

        $this->assertFalse($more, 'a batch smaller than the size asked for reported more work');
        $this->assertSame(['moved', 'moved', 'moved'], $this->codes($ids));
    }

    /**
     * The receipt sequence is gap-free because a tax authority reads it that
     * way, so a number drawn for a row that never landed has to go back. The
     * counter increment is a wp_options UPDATE on the same connection as the
     * insert, which is the only reason the transaction can take it back.
     */
    public function test_a_receipt_insert_that_is_refused_does_not_spend_a_number(): void
    {
        // A number already on an issued receipt, with the counter behind it:
        // what a restore from an export leaves when the counter lags the rows.
        $taken = $this->reserveNumberOnAnotherReceipt();
        $this->assertSame(1, $this->counter()->peekNext('receipt'), 'precondition: the counter has not moved');

        $donation = $this->paidDonation('collides@example.test');

        // The refusal is the point of the test, so keep wpdb from printing it.
        $quiet = self::$wpdb->suppress_errors(true);
        $hidden = self::$wpdb->hide_errors();
        try {
            do_action('dono.async.issue_receipt', ['donation_id' => (int) $donation->id]);
            $this->fail('the refused insert did not reach the caller');
        } catch (Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('duplicate', $e->getMessage());
        } finally {
            self::$wpdb->suppress_errors($quiet);
            if ($hidden) self::$wpdb->show_errors();
        }

        $this->assertNull(
            Receipt::query()->where('donation_id', (int) $donation->id)->get(),
            'a receipt row survived the refused insert'
        );
        $this->assertSame(
            1,
            $this->counter()->peekNext('receipt'),
            "the counter spent {$taken} on a receipt that was never written, so the sequence now skips it"
        );
    }

    /**
     * The control for the case above: with nothing in the way the same call
     * writes the row and moves the counter, so what the rollback undoes is a
     * write that really does happen.
     */
    public function test_an_issue_that_succeeds_writes_the_row_and_moves_the_counter(): void
    {
        $donation = $this->paidDonation('lands@example.test');

        do_action('dono.async.issue_receipt', ['donation_id' => (int) $donation->id]);

        $receipt = Receipt::query()->where('donation_id', (int) $donation->id)->get();
        $this->assertInstanceOf(Receipt::class, $receipt, 'the issuer produced no receipt');
        $this->assertMatchesRegularExpression('/^REC-\d{4}-00001$/', (string) $receipt->receipt_number);
        $this->assertSame(2, $this->counter()->peekNext('receipt'));
    }

    /**
     * The race createReceiptRecord is written for: two issue jobs for the same
     * donation, the second allocating its number while the first inserts. The
     * loser has to give the number back, or the sequence skips one for a
     * receipt nobody holds.
     *
     * Driven at createReceiptRecord because processRenderer's own lookup
     * happens before the allocation, so the only way in through the public
     * path is to already be past it.
     */
    public function test_losing_the_insert_race_gives_the_number_back(): void
    {
        $donation = $this->paidDonation('raced@example.test');
        $renderer = $this->renderer();

        // The winner: same donation, same renderer, holding the number the
        // loser is about to draw.
        $winner = Receipt::make();
        $winner->donation_id    = (int) $donation->id;
        $winner->donor_id       = (int) $donation->donor_id;
        $winner->renderer_id    = $renderer->id();
        $winner->locale         = 'en';
        $winner->receipt_number = 'REC-ALREADY-MINE';
        $winner->voided         = false;
        $winner->issued_at      = gmdate('Y-m-d H:i:s');
        $winner->save();

        $quiet  = self::$wpdb->suppress_errors(true);
        $hidden = self::$wpdb->hide_errors();
        try {
            [$receipt, $created] = $this->createReceiptRecord($renderer, $this->context($donation));
        } finally {
            self::$wpdb->suppress_errors($quiet);
            if ($hidden) self::$wpdb->show_errors();
        }

        $this->assertFalse($created, 'the loser reported itself as the issuing runner');
        $this->assertSame((int) $winner->id, (int) $receipt->id, 'the loser did not fall back on the winning row');
        $this->assertSame(
            1,
            Receipt::query()->where('donation_id', (int) $donation->id)->count(),
            'the loser left a second receipt on the donation'
        );
        $this->assertSame(
            1,
            $this->counter()->peekNext('receipt'),
            'the loser kept the number it drew, so the sequence skips it'
        );
    }

    /**
     * recordPayment is three writes on one plan: the timestamps and the dunning
     * reset, then two atomic increments. The middle one landing without the
     * third would leave a plan that says it took a payment of nothing, and the
     * donor screen reads those counters straight.
     *
     * The last write is refused here by asking the unsigned total to go below
     * zero. What matters is that it is the database refusing the third write
     * after the first two have already run.
     */
    public function test_a_renewal_write_the_database_refuses_takes_the_earlier_two_back(): void
    {
        $plan = $this->plan('sub_refused', 500);
        $plan->failed_renewals_count = 2;
        $plan->save();

        $quiet = self::$wpdb->suppress_errors(true);
        $hidden = self::$wpdb->hide_errors();
        try {
            $this->plans()->recordPayment($plan, -100000, gmdate('Y-m-d H:i:s'));
            $this->fail('the refused write did not reach the caller');
        } catch (Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('out of range', $e->getMessage());
        } finally {
            self::$wpdb->suppress_errors($quiet);
            if ($hidden) self::$wpdb->show_errors();
        }

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame(0, (int) $fresh->payments_count, 'the payment count moved for a renewal that was refused');
        $this->assertSame(500, (int) $fresh->total_paid_cents, 'the total moved for a renewal that was refused');
        $this->assertNull($fresh->last_payment_at, 'the plan is dated to a renewal it never took');
        $this->assertSame(2, (int) $fresh->failed_renewals_count, 'dunning was reset by a renewal that was refused');

        // The caller holds this object and reads the counters off it.
        $this->assertSame(0, (int) $plan->payments_count, 'the in-memory plan counted a renewal the database refused');
        $this->assertSame(500, (int) $plan->total_paid_cents);
        $this->assertNull($plan->last_payment_at);
    }

    /** The control: nothing in the way, and all three writes land. */
    public function test_a_renewal_that_is_accepted_lands_all_three_writes(): void
    {
        $plan = $this->plan('sub_accepted', 500);
        $plan->failed_renewals_count = 2;
        $plan->save();

        $when = gmdate('Y-m-d H:i:s');
        $this->plans()->recordPayment($plan, 1000, $when, $when);

        $fresh = RecurringPlan::query()->where('id', (int) $plan->id)->get();
        $this->assertSame(1, (int) $fresh->payments_count);
        $this->assertSame(1500, (int) $fresh->total_paid_cents);
        $this->assertSame($when, (string) $fresh->last_payment_at);
        $this->assertSame(0, (int) $fresh->failed_renewals_count);
    }

    private function plans(): RecurringPlanRepository
    {
        return Plugin::instance()->container->get(RecurringPlanRepository::class);
    }

    private function plan(string $subscriptionId, int $totalPaidCents): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id                = 1;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = $subscriptionId;
        $plan->amount_cents            = 1000;
        $plan->currency                = 'USD';
        $plan->started_at              = $now;
        $plan->total_paid_cents        = $totalPaidCents;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        return $plan;
    }

    /** The number the next receipt would draw, already spent by an unrelated receipt row. */
    private function reserveNumberOnAnotherReceipt(): string
    {
        $other  = $this->paidDonation('already-issued@example.test');
        $number = $this->counter()->format('receipt', (int) gmdate('Y'), 1);

        $receipt = Receipt::make();
        $receipt->donation_id    = (int) $other->id;
        $receipt->donor_id       = (int) $other->donor_id;
        $receipt->renderer_id    = 'generic.v1';
        $receipt->locale         = 'en';
        $receipt->receipt_number = $number;
        $receipt->voided         = false;
        $receipt->issued_at      = gmdate('Y-m-d H:i:s');
        $receipt->save();

        return $number;
    }

    private function counter(): ReferenceGenerator
    {
        return Plugin::instance()->container->get(ReferenceGenerator::class);
    }

    private function paidDonation(string $email): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $donor = Donor::make();
        $donor->email_encrypted = 'enc-' . $email;
        $donor->email_hash      = hash('sha256', $email);
        $donor->first_name      = 'Rowan';
        $donor->last_name       = 'Ledger';
        $donor->created_at      = $now;
        $donor->updated_at      = $now;
        $donor->save();

        $donation = Donation::make();
        $donation->reference         = 'DONO-T-' . bin2hex(random_bytes(4));
        $donation->donor_id          = (int) $donor->id;
        $donation->amount_cents      = 2500;
        $donation->currency          = 'USD';
        $donation->base_amount_cents = 2500;
        $donation->gateway           = 'offline';
        $donation->status            = 'paid';
        $donation->paid_at           = $now;
        $donation->created_at        = $now;
        $donation->updated_at        = $now;
        $donation->save();

        return $donation;
    }

    /**
     * @param  list<int> $ids
     * @return \Closure(array<mixed>):void
     */
    private function stampingThenThrowing(string $code): \Closure
    {
        return static function (array $batch) use ($code): void {
            $seen = 0;
            foreach ($batch as $id) {
                if ($seen === 1) {
                    throw new RuntimeException(self::APPLY_FAILURE);
                }
                Fund::query()->where('id', $id)->update(['accounting_code' => $code]);
                $seen++;
            }
        };
    }

    /** @return list<int> */
    private function threeFunds(string $tag): array
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $suffix) {
            $fund = Fund::make();
            $fund->code       = $tag . '-' . $suffix;
            $fund->name       = 'Batch ' . $tag . ' ' . $suffix;
            $fund->created_at = gmdate('Y-m-d H:i:s');
            $fund->updated_at = gmdate('Y-m-d H:i:s');
            $fund->save();
            $ids[] = (int) $fund->id;
        }

        return $ids;
    }

    /**
     * @param  list<int> $ids
     * @return list<?string>
     */
    private function codes(array $ids): array
    {
        return array_map(
            static fn (int $id): ?string => Fund::query()->where('id', $id)->get()->accounting_code,
            $ids
        );
    }
    /**
     * @return array{0: Receipt, 1: bool}
     */
    private function createReceiptRecord(ReceiptRenderer $renderer, ReceiptContext $ctx): array
    {
        $method = new \ReflectionMethod(ReceiptIssuer::class, 'createReceiptRecord');
        $method->setAccessible(true);

        return $method->invoke(Plugin::instance()->container->get(ReceiptIssuer::class), $renderer, $ctx);
    }

    private function renderer(): ReceiptRenderer
    {
        $renderers = (array) apply_filters('dono.receipt.renderers', []);
        $this->assertNotEmpty($renderers, 'no receipt renderer is registered');

        return $renderers[0];
    }

    private function context(Donation $donation): ReceiptContext
    {
        return new ReceiptContext(
            donation: $donation,
            donor:    Donor::query()->where('id', (int) $donation->donor_id)->get(),
            locale:   'en',
            org:      ['name' => 'Test Org'],
        );
    }
}
