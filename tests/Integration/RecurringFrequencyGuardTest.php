<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use RuntimeException;

/**
 * A gateway that creates no schedule must not take a recurring donation.
 *
 * The offline gateway states the failure it is avoiding: a recurring option on
 * a gateway with no stored payment method "would create a plan that silently
 * never charges again". Only the public donation route enforced that, by
 * re-resolving the form's gateway options. Every other caller reaches
 * DonationService::createPending() directly, so the rule lives there too.
 */
final class RecurringFrequencyGuardTest extends IntegrationTestCase
{
    private function donations(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function intent(string $gateway, string $frequency): DonationIntent
    {
        return new DonationIntent(
            email:        'guard-' . uniqid() . '@dono.test',
            amount_cents: 2500,
            currency:     'EUR',
            gateway:      $gateway,
            frequency:    $frequency,
        );
    }

    public function test_a_recurring_donation_on_a_one_time_gateway_is_refused(): void
    {
        $before = Donation::query()->count();

        $this->expectException(RuntimeException::class);

        try {
            $this->donations()->createPending($this->intent('offline', 'monthly'));
        } finally {
            $this->assertSame($before, Donation::query()->count(), 'nothing may be written');
        }
    }

    public function test_an_unregistered_gateway_cannot_take_a_recurring_donation(): void
    {
        // Fails closed: an unknown gateway cannot be asked what it supports, so
        // it does not get the benefit of the doubt.
        $this->expectException(RuntimeException::class);
        $this->donations()->createPending($this->intent('no_such_gateway', 'weekly'));
    }

    public function test_a_one_time_donation_on_a_one_time_gateway_still_works(): void
    {
        // The guard is capability-only. It must not become a general gateway
        // filter: admin manual entry books offline donations through here.
        $result = $this->donations()->createPending($this->intent('offline', 'one_time'));

        $this->assertArrayHasKey('donation', $result);
        $this->assertSame('offline', $result['donation']->gateway);
    }
}
