<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Receipts\Receipt;
use Gratora\Recurring\RecurringPlan;

/**
 * What keeps a donor is a donation that cannot be deleted, asked of the rules
 * that own that answer rather than of a window of this gate's own. Whether a
 * donation took money is not one of those rules: the delete closes the payment
 * at the processor first and takes the money back out of the totals, so the
 * refusals left are the ones somebody outside the organisation would feel,
 * a receipt an authority can ask for and a signup whose row is the mandate.
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

    /**
     * Money already taken is not a refusal. It comes back out of the totals
     * when the row goes, and the audit row keeps what it was, which is the
     * whole of what a delete can honestly promise.
     */
    public function test_a_paid_donation_does_not_keep_its_donor(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, ['status' => 'paid', 'paid_at' => $this->longAgo(), 'created_at' => $this->longAgo()]);

        $this->assertNull($this->reason($donor));
    }

    /** Nor does a payment still on its way, or the age of one. */
    public function test_neither_does_a_cheque_still_in_the_post(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, [
            'status'     => 'pending',
            'gateway'    => 'offline',
            'created_at' => $this->longAgo(),
        ]);

        $this->assertNull($this->reason($donor));
    }

    public function test_neither_does_a_failure_that_left_a_transaction_id(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, [
            'status'         => 'failed',
            'gateway_txn_id' => 'ch_left_behind',
            'created_at'     => $this->longAgo(),
        ]);

        $this->assertNull($this->reason($donor));
    }

    /**
     * A receipt that still stands is a document an authority can ask for, and
     * the numbering it sits in has to stay gap free. Refunding the donation
     * withdraws it, which is the way out.
     */
    public function test_a_receipt_that_still_stands_keeps_its_donor(): void
    {
        $donor    = $this->donor();
        $donation = $this->donation((int) $donor->id, ['status' => 'paid', 'paid_at' => $this->longAgo()]);

        $r = Receipt::make();
        $r->donation_id    = (int) $donation->id;
        $r->renderer_id    = 'receipt';
        $r->receipt_number = 'R-' . bin2hex(random_bytes(3));
        $r->locale         = 'en_US';
        $r->voided         = false;
        $r->issued_at      = gmdate('Y-m-d H:i:s');
        $r->save();

        $this->assertStringContainsString('receipt', strtolower((string) $this->reason($donor)));
    }

    /**
     * PayPal charges the moment the donor approves, so this row is the only
     * thing that can show or cancel the subscription it started.
     */
    public function test_a_paypal_recurring_signup_keeps_its_donor(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, [
            'status'    => 'paid',
            'paid_at'   => $this->longAgo(),
            'gateway'   => 'paypal',
            'frequency' => 'monthly',
        ]);

        $this->assertStringContainsString('PayPal', (string) $this->reason($donor));
    }

    /**
     * The rules that own the answer are asked for it, so an add-on that
     * refuses one donation refuses the donor here rather than inside the
     * transaction, after the payments have already been closed.
     */
    public function test_an_add_on_refusing_one_donation_keeps_the_donor(): void
    {
        $donor = $this->donor();
        $this->donation((int) $donor->id, ['status' => 'failed', 'created_at' => $this->longAgo()]);

        add_filter('gratora.donation.undeletable_reason', static fn () => 'An add-on still needs this.');

        try {
            $this->assertStringContainsString('An add-on still needs this.', (string) $this->reason($donor));
        } finally {
            remove_all_filters('gratora.donation.undeletable_reason');
        }
    }

    /**
     * A plan is not a refusal. The mandate is stopped by the delete itself, so
     * the gate stays quiet and the button is offered; what refuses, when it
     * refuses, is a processor that will not answer.
     *
     * @see DonorDeleteStopsMandateTest
     */
    public function test_a_recurring_plan_does_not_refuse_the_delete_by_itself(): void
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

            $this->assertNull($this->reason($donor), "a {$status} plan must not be its own refusal");
        }
    }
}
