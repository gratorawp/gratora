<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationDeleter;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\RecurringPlan;
use Gratora\Vendor\Queryable\DB;
use InvalidArgumentException;

/**
 * Removing a donation that belongs to a subscription.
 *
 * The refusal here is about a mandate that is still billing, so it has to lift
 * once the mandate is cancelled. A refusal that reads as advice but never
 * changes its mind is worse than no way out at all: the operator does the
 * thing it asked for and gets the same sentence back.
 *
 * What a plan's counters say is the other half. They are stored, not derived,
 * so a payment deleted without touching them leaves the plan claiming money it
 * can no longer show, silently and for good.
 */
final class DeleteRecurringDonationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function deleter(): DonationDeleter
    {
        return Plugin::instance()->container->get(DonationDeleter::class);
    }

    private function plan(string $status = 'active', string $gateway = 'stripe'): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('recur-' . uniqid() . '@example.test', ['first_name' => 'Recur'])->id;
        $p->gateway                 = $gateway;
        $p->gateway_subscription_id = 'sub_' . bin2hex(random_bytes(3));
        $p->status                  = $status;
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->payments_count          = 2;
        $p->total_paid_cents        = 5000;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    private function payment(?RecurringPlan $plan, array $overrides = []): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'RECUR-' . bin2hex(random_bytes(4));
        $d->donor_id          = $plan !== null
            ? (int) $plan->donor_id
            : (int) Plugin::instance()->container->get(DonorService::class)
                ->findOrCreate('recur-' . uniqid() . '@example.test', ['first_name' => 'Recur'])->id;
        $d->recurring_plan_id = $plan !== null ? (int) $plan->id : null;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = $plan !== null ? (string) $plan->gateway : 'paypal';
        $d->frequency         = 'monthly';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->gateway_txn_id    = 'TXN-' . bin2hex(random_bytes(3));
        $d->created_at        = $now;
        $d->updated_at        = $now;
        foreach ($overrides as $k => $v) {
            $d->{$k} = $v;
        }
        $d->save();

        return $d;
    }

    public function test_a_payment_on_a_live_plan_refuses_and_names_the_plan(): void
    {
        $plan     = $this->plan('active');
        $donation = $this->payment($plan);

        try {
            $this->deleter()->delete($donation, null, false);
            $this->fail('a payment on a plan that is still billing must refuse');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString((string) $plan->id, $e->getMessage(), 'the operator has to be able to find the plan');
        }

        $this->assertNotNull(Donation::query()->find('id', (int) $donation->id));
    }

    /** The refusal asked for a cancel, so a cancel has to be enough. */
    public function test_the_refusal_lifts_once_the_plan_is_cancelled(): void
    {
        $plan     = $this->plan('cancelled');
        $donation = $this->payment($plan);

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(Donation::query()->find('id', (int) $donation->id));
    }

    /**
     * PayPal charges on approval, and this row was the only trace of that. It
     * was also a permanent refusal: no plan exists to cancel, so the way out
     * the message named did not exist. The trace moves to the audit row, which
     * outlives the donation and is not in anybody's way.
     */
    public function test_a_paypal_signup_with_no_plan_goes_and_the_audit_keeps_what_it_was(): void
    {
        $donation = $this->payment(null, ['gateway' => 'paypal', 'gateway_txn_id' => 'PAYID-SUB-1']);
        $id       = (int) $donation->id;

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(Donation::query()->find('id', $id));

        $audit = Event::query()->where('type', 'donation.deleted')->where('donation_id', $id)->get();
        $this->assertNotNull($audit, 'the removal is recorded');

        $payload = (array) $audit->payload;
        $this->assertSame('monthly', $payload['subscription']['frequency'] ?? null);
        $this->assertSame('PAYID-SUB-1', $payload['subscription']['gateway_txn_id'] ?? null);
    }

    /**
     * The plan's totals are stored, so nothing recomputes them on its own. A
     * renewal removed without them leaves the plan saying it has taken money
     * that no row can account for.
     */
    public function test_removing_a_payment_takes_it_out_of_the_plans_own_totals(): void
    {
        $plan = $this->plan('cancelled');
        $kept = $this->payment($plan);
        $gone = $this->payment($plan);

        $this->deleter()->delete($gone, null, false);

        $after = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(1, (int) $after->payments_count, 'one fewer payment');
        $this->assertSame(2500, (int) $after->total_paid_cents, 'and the amount it took with it');
        $this->assertNotNull(Donation::query()->find('id', (int) $kept->id));
    }

    /**
     * Derived, not decremented. Nothing has ever recomputed these counters, so
     * a plan reaching this has no reason to be right beforehand: taking one
     * payment off a wrong number leaves a wrong number.
     */
    public function test_the_plans_totals_are_recomputed_rather_than_adjusted(): void
    {
        $plan = $this->plan('cancelled');
        DB::table('gratora_recurring_plans')
            ->where('id', (int) $plan->id)
            ->update(['payments_count' => 97, 'total_paid_cents' => 999999]);

        $this->payment($plan);
        $this->deleter()->delete($this->payment($plan), null, false);

        $after = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(1, (int) $after->payments_count);
        $this->assertSame(2500, (int) $after->total_paid_cents);
    }

    /**
     * A ticket order's charge is not core's to refuse. Core was telling the
     * operator to manage it from the order, and the add-on that owns orders
     * has no way to remove one: the refusal named nothing anybody could do.
     * The add-on takes the order when the charge goes, and refuses through
     * its own filter if it ever has a reason to.
     */
    public function test_a_ticket_order_payment_is_not_refused_by_core(): void
    {
        $donation = $this->payment(null, ['kind' => 'order', 'frequency' => 'one_time', 'gateway' => 'stripe']);
        $id       = (int) $donation->id;

        $this->deleter()->delete($donation, null, false);

        $this->assertNull(Donation::query()->find('id', $id));
    }

    /** A one-off donation is not touched by any of this. */
    public function test_a_one_off_donation_records_no_subscription(): void
    {
        $donation = $this->payment(null, ['frequency' => 'one_time', 'gateway' => 'stripe']);
        $id       = (int) $donation->id;

        $this->deleter()->delete($donation, null, false);

        $audit   = Event::query()->where('type', 'donation.deleted')->where('donation_id', $id)->get();
        $payload = (array) $audit->payload;
        $this->assertArrayNotHasKey('subscription', $payload);
    }
}
