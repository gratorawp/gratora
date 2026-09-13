<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorRetention;
use Gratora\Recurring\PlanStatus;
use Gratora\Recurring\RecurringPlan;
use Gratora\Settings\SettingsService;
use Gratora\Foundation\Plugin;

/**
 * The nightly sweep erases donors nobody has heard from in years, and its
 * docblock says it leaves alone anyone with a recurring plan.
 *
 * It asked for active or paused, which is the narrowest reading of that in the
 * plugin. A donor whose PayPal subscription was suspended years ago sits in
 * past_due and never leaves it on its own; one whose subscription PayPal never
 * activated sits in pending. Both were swept, and redact() cancels the mandate
 * on the way past, so a live subscription was ended by a job the donor never
 * asked for. The erasure inside that same sweep considers those exact rows
 * live enough to need cancelling.
 */
final class RetentionProtectsAMandateTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option('gratora_privacy');
        delete_option(DonorRetention::STARTS_AT_OPTION);
        parent::tearDown();
    }

    private function sweepIsDue(): void
    {
        Plugin::instance()->container->get(SettingsService::class)
            ->update('privacy', ['erase_inactive_donors' => true, 'donor_retention_years' => 1]);

        update_option(DonorRetention::STARTS_AT_OPTION, time() - 86400, false);
    }

    private function ancientDonor(): Donor
    {
        $old = gmdate('Y-m-d H:i:s', time() - (20 * 365 * 86400));

        $d = Donor::make();
        $d->email_hash       = hash('sha256', uniqid('mandate', true));
        $d->email_encrypted  = 'x';
        $d->first_name       = 'Ancient';
        $d->last_name        = 'Donor';
        $d->last_donation_at = $old;
        $d->created_at       = $old;
        $d->updated_at       = $old;
        $d->save();

        return $d;
    }

    private function planFor(Donor $donor, string $status): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'paypal';
        $p->gateway_subscription_id = 'I-' . bin2hex(random_bytes(4));
        $p->amount_cents            = 2000;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = $status;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();
    }

    private function wasErased(Donor $donor): bool
    {
        $fresh = Donor::query()->where('id', (int) $donor->id)->get();

        return $fresh === null || $fresh->redacted_at !== null;
    }

    /** @return array<string, array{0:string}> */
    public static function mandates(): array
    {
        return [
            'suspended at the gateway'  => ['past_due'],
            'approved but not started'  => ['pending'],
            'stopped by the donor'      => ['paused'],
            'collecting'                => ['active'],
        ];
    }

    /** @dataProvider mandates */
    public function test_a_donor_holding_a_mandate_is_left_alone(string $status): void
    {
        $donor = $this->ancientDonor();
        $this->planFor($donor, $status);

        $this->sweepIsDue();
        Plugin::instance()->container->get(DonorRetention::class)->run();

        $this->assertFalse($this->wasErased($donor), 'the gateway can still take money on this one');
    }

    /** The sweep still does its job on a donor who holds nothing. */
    public function test_a_donor_whose_plan_has_ended_is_swept(): void
    {
        $donor = $this->ancientDonor();
        $this->planFor($donor, 'cancelled');

        $this->sweepIsDue();
        Plugin::instance()->container->get(DonorRetention::class)->run();

        $this->assertTrue($this->wasErased($donor));
    }

    /**
     * Privacy settings shows how many donors the next sweep takes, from the
     * same predicate. A protected donor counted there is a number an owner
     * decides on.
     */
    public function test_the_preview_does_not_count_a_donor_holding_a_mandate(): void
    {
        $donor = $this->ancientDonor();
        $this->planFor($donor, 'pending');

        $this->sweepIsDue();

        $this->assertSame(
            0,
            (int) Plugin::instance()->container->get(DonorRetention::class)->preview()['eligible_now']
        );
    }

    /** Every status the sweep must step over is one the vocabulary knows. */
    public function test_the_protected_set_is_the_lifecycle_less_the_ended(): void
    {
        $this->assertSame(
            array_values(array_diff(PlanStatus::LIFECYCLE, PlanStatus::TERMINAL)),
            PlanStatus::LIVE
        );
    }
}
