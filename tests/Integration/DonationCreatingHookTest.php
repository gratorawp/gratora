<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Vendor\Queryable\DB;
use ReflectionProperty;

/**
 * The seam an add-on needs to write a row that belongs to a donation.
 *
 * `dono.donation.intent_created` fires after the transaction has committed, so
 * anything it writes can survive a donation that did not. This one runs inside
 * the transaction, with the donation already saved and its id available.
 */
final class DonationCreatingHookTest extends IntegrationTestCase
{
    public function test_it_hands_over_the_saved_donation_and_the_intent(): void
    {
        $seen = [];
        add_action('dono.donation.creating', static function ($donation, $intent) use (&$seen): void {
            $seen[] = [$donation, $intent];
        }, 10, 2);

        $intent  = $this->intent(['souvenir' => 'brass plaque']);
        $created = $this->service()->createPending($intent);

        $this->assertCount(1, $seen, 'the hook fires exactly once per created donation');
        [$donation, $handed] = $seen[0];

        $this->assertInstanceOf(Donation::class, $donation);
        $this->assertGreaterThan(0, (int) $donation->id, 'the donation is already saved, so its id is usable as a foreign key');
        $this->assertSame((int) $created['donation']->id, (int) $donation->id);
        $this->assertSame('brass plaque', $handed->extra['souvenir'] ?? null, 'the intent reaches the subscriber with its extra bag intact');
    }

    /** An add-on row must live or die with the donation, so the hook runs inside the transaction. */
    public function test_it_runs_inside_the_create_transaction(): void
    {
        $depthInside = null;
        add_action('dono.donation.creating', static function () use (&$depthInside): void {
            $depthInside = self::transactionDepth();
        });

        $before = self::transactionDepth();
        $this->service()->createPending($this->intent());

        $this->assertNotNull($depthInside);
        $this->assertSame($before + 1, $depthInside, 'the subscriber is one transaction deeper than the caller');
        $this->assertSame($before, self::transactionDepth(), 'the transaction is closed again afterwards');
    }

    /** Ordering matters: the row exists by the time observers of the committed donation run. */
    public function test_it_fires_before_the_committed_intent_created_broadcast(): void
    {
        $order = [];
        add_action('dono.donation.creating', static function () use (&$order): void {
            $order[] = 'creating';
        });
        add_action('dono.donation.intent_created', static function () use (&$order): void {
            $order[] = 'intent_created';
        });

        $this->service()->createPending($this->intent());

        $this->assertSame(['creating', 'intent_created'], $order);
    }

    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    /** @param array<string,mixed> $extra */
    private function intent(array $extra = []): DonationIntent
    {
        return new DonationIntent(
            email: 'creating-hook@example.test',
            amount_cents: 2500,
            currency: 'USD',
            gateway: 'offline',
            profile: ['first_name' => 'Iris', 'last_name' => 'Ledger'],
            extra: $extra,
        );
    }

    private static function transactionDepth(): int
    {
        $prop = new ReflectionProperty(DB::class, 'transactionDepth');
        $prop->setAccessible(true);

        return (int) $prop->getValue();
    }
}
