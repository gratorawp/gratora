<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Donations;

use Dono\Donations\DonationIntent;
use Error;
use PHPUnit\Framework\TestCase;

final class DonationIntentTest extends TestCase
{
    public function test_required_fields_populate(): void
    {
        $i = new DonationIntent(
            email:        'sarah@example.com',
            amount_cents: 5000,
            currency:     'EUR',
            gateway:      'stripe',
        );

        $this->assertSame('sarah@example.com', $i->email);
        $this->assertSame(5000, $i->amount_cents);
        $this->assertSame('EUR', $i->currency);
        $this->assertSame('stripe', $i->gateway);
        $this->assertSame('one_time', $i->frequency, 'default frequency');
        $this->assertFalse($i->is_anonymous, 'default is_anonymous=false');
    }

    public function test_profile_and_attribution_are_preserved(): void
    {
        $i = new DonationIntent(
            email:        'sarah@example.com',
            amount_cents: 5000,
            currency:     'EUR',
            gateway:      'offline',
            profile:      ['first_name' => 'Sarah', 'country' => 'DE'],
            source_attribution: ['utm_source' => 'fb', 'page' => '/donate'],
        );

        $this->assertSame(['first_name' => 'Sarah', 'country' => 'DE'], $i->profile);
        $this->assertSame(['utm_source' => 'fb', 'page' => '/donate'], $i->source_attribution);
    }

    public function test_intent_is_immutable(): void
    {
        $i = new DonationIntent(
            email:        'sarah@example.com',
            amount_cents: 5000,
            currency:     'EUR',
            gateway:      'offline',
        );

        $this->expectException(Error::class);
        // readonly property - assignment is a fatal-like Error in PHP 8.1+
        $i->amount_cents = 999;
    }

    public function test_extra_defaults_empty_and_round_trips(): void
    {
        $bare = new DonationIntent(
            email:        'sarah@example.com',
            amount_cents: 5000,
            currency:     'EUR',
            gateway:      'offline',
        );
        $this->assertSame([], $bare->extra);

        $withExtra = new DonationIntent(
            email:        'sarah@example.com',
            amount_cents: 5000,
            currency:     'EUR',
            gateway:      'offline',
            extra:        ['form_type' => 'p2p', 'fundraiser_id' => 42],
        );
        $this->assertSame(['form_type' => 'p2p', 'fundraiser_id' => 42], $withExtra->extra);
    }
}
