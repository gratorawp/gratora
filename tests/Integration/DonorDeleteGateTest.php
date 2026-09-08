<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Recurring\RecurringPlan;

/**
 * A donor is kept while any donation of theirs could still become money. The
 * gate reads the same window the abandon sweep uses, so a row it has retired
 * is a row this releases.
 */
final class DonorDeleteGateTest extends IntegrationTestCase
{
    private function donor(): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('gate-' . uniqid() . '@example.test', ['first_name' => 'Gate']);
    }

    private function donation(int $donorId, array $attrs): Donation
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = 'GATE-' . uniqid();
        $d->donor_id          = $donorId;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'EUR';
        $d->base_currency     = 'EUR';
        $d->status            = 'pending';
        $d->gateway           = 'stripe';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        foreach ($attrs as $k => $v) {
            $d->{$k} = $v;
        }
        $d->save();

        return $d;
    }

    private function reason(Donor $donor): ?string
    {
        return Plugin::instance()->container->get(DonorService::class)->undeletableReason($donor);
    }

    private function longAgo(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
    }

    public function test_a_donor_with_no_donations_is_deletable(): void
    {
        $this->assertNull($this->reason($this->donor()));
    }

    public function test_a_spent_checkout_no_longer_blocks(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, ['status' => 'failed', 'created_at' => $this->longAgo()]);

        $this->assertNull($this->reason($donor), 'a failed checkout past the window is litter, not a record');
    }

    public function test_a_paid_donation_still_blocks(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, ['status' => 'paid', 'paid_at' => $this->longAgo(), 'created_at' => $this->longAgo()]);

        $this->assertNotNull($this->reason($donor));
    }

    public function test_a_cheque_still_in_the_post_blocks(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, [
            'status'     => 'pending',
            'gateway'    => 'offline',
            'created_at' => $this->longAgo(),
        ]);

        $this->assertNotNull($this->reason($donor), 'a pending donation is never litter, whatever its age');
    }

    public function test_a_failed_row_that_took_money_still_blocks(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, [
            'status'         => 'failed',
            'gateway_txn_id' => 'ch_left_behind',
            'created_at'     => $this->longAgo(),
        ]);

        $this->assertNotNull($this->reason($donor), 'a transaction id is a reconciliation question');
    }

    public function test_a_recent_failure_still_blocks(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, ['status' => 'failed']);

        $this->assertNotNull($this->reason($donor), 'inside the window a retry is still expected');
    }

    /**
     * Any plan at all, whatever its local status. A cancelled row still holds
     * the gateway handle, and an importer writes 'cancelled' over statuses it
     * has no state for, which may still be billing.
     */
    public function test_any_recurring_plan_blocks_whatever_its_status(): void
    {
        foreach (['cancelled', 'active', 'pending', 'paused', 'past_due'] as $status) {
            $donor = $this->donor();
            $now   = gmdate('Y-m-d H:i:s');

            $p = RecurringPlan::make();
            $p->donor_id                = (int) $donor->id;
            $p->gateway                 = 'stripe';
            $p->gateway_subscription_id = 'sub_' . uniqid();
            $p->amount_cents            = 1000;
            $p->currency                = 'EUR';
            $p->interval_unit           = 'month';
            $p->interval_count          = 1;
            $p->status                  = $status;
            $p->is_test                 = false;
            $p->created_at              = $now;
            $p->updated_at              = $now;
            $p->save();

            $this->assertNotNull($this->reason($donor), "a {$status} plan must keep its donor");
        }
    }
}
