<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Plugin;
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
            email:        'guard-' . uniqid() . '@gratora.test',
            amount_cents: 2500,
            currency:     'EUR',
            gateway:      $gateway,
            frequency:    $frequency,
        );
    }

    /**
     * An import writes down money already taken, on a schedule that already
     * existed at the source. Refusing it loses the donor's payment history
     * while the plan it belongs to imports beside it, leaving a plan whose
     * every counter reads zero. The gateway question is about opening a new
     * schedule, and a migrating charity has usually not connected its gateway
     * yet, so this fired on every renewal it owned.
     */
    public function test_a_donation_already_collected_elsewhere_is_recorded_on_a_one_time_gateway(): void
    {
        $intent = new DonationIntent(
            email:             'collected-' . uniqid() . '@gratora.test',
            amount_cents:      2500,
            currency:          'EUR',
            gateway:           'offline',
            frequency:         'monthly',
            already_collected: true,
        );

        ['donation' => $donation] = $this->donations()->createPending($intent);

        $this->assertInstanceOf(Donation::class, $donation);
        $this->assertSame('monthly', (string) $donation->frequency, 'the history says monthly and must keep saying so');
    }

    /**
     * The flag is read before gratora.donation.intent_creating, the same defence
     * $retry has. An add-on that could set it would be able to walk a live
     * donor's submission past the guard and promise them a plan nothing will
     * ever collect.
     */
    public function test_a_filter_cannot_declare_a_live_donation_already_collected(): void
    {
        add_filter('gratora.donation.intent_creating', static function (DonationIntent $intent): DonationIntent {
            return new DonationIntent(
                email:             $intent->email,
                amount_cents:      $intent->amount_cents,
                currency:          $intent->currency,
                gateway:           $intent->gateway,
                frequency:         $intent->frequency,
                already_collected: true,
            );
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('creates no recurring schedule');

        try {
            $this->donations()->createPending($this->intent('offline', 'monthly'));
        } finally {
            remove_all_filters('gratora.donation.intent_creating');
        }
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
