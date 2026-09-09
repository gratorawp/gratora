<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Campaigns\Campaign;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Plugin;
use RuntimeException;

/**
 * Keep aggregate recalculation outside the payment transaction so lock waits cannot roll back
 * recorded money.
 */
final class AggregateResyncOutsideMoneyTxnTest extends IntegrationTestCase
{
    private const SEAM_FAILURE = 'the storage layer refused this write';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeOfflinePayable();
    }

    public function test_a_counter_that_cannot_be_written_does_not_undo_the_payment(): void
    {
        [$campaignId, $donation] = $this->pendingDonation('counter-fails@example.test');

        $this->whileQueryThrows(
            static fn (string $sql): bool => str_contains($sql, 'UPDATE')
                && str_contains($sql, 'gratora_campaigns'),
            fn () => $this->service()->confirm($donation, ['gateway_txn_id' => 'txn_counter_fails'])
        );

        $row = $this->donationRow((int) $donation->id);
        $this->assertSame('paid', (string) $row->status, 'the money the gateway took is still taken');
        $this->assertNotNull($row->paid_at);

        $this->assertSame(
            0,
            (int) Campaign::query()->where('id', $campaignId)->get()->raised_cents,
            'the counter is merely stale, which Tools, Recalculate repairs'
        );

        $this->assertGreaterThan(
            0,
            Event::query()->where('type', 'error.donations.aggregates')->count(),
            'and the operator can read why it is stale'
        );
    }

    /**
     * The recompute is three whole-scope aggregates. Holding the donation's own
     * write lock across them keeps the money transaction open for the length of
     * the scans, and the lock the syncer takes to stop two confirmations
     * overwriting each other reads the caller's older snapshot from in there,
     * so it settles nothing.
     */
    public function test_the_counters_are_recomputed_after_the_money_is_committed(): void
    {
        [, $donation] = $this->pendingDonation('after-commit@example.test');

        $statements = [];
        $filter = static function ($sql) use (&$statements) {
            $statements[] = (string) $sql;
            return $sql;
        };

        add_filter('query', $filter);
        try {
            $this->service()->confirm($donation, ['gateway_txn_id' => 'txn_after_commit']);
        } finally {
            remove_filter('query', $filter);
        }

        $paid = $this->firstMatch(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'UPDATE')
                && str_contains($sql, 'gratora_donations')
                && str_contains($sql, "'paid'")
        );
        $this->assertNotNull($paid, 'the donation was flipped to paid');

        // The money transaction ends at the first commit after that flip; the
        // syncers open and close their own after it.
        $committed = $this->firstMatch(
            $statements,
            static fn (string $sql): bool => (bool) preg_match('/^\s*(COMMIT|RELEASE SAVEPOINT)/i', $sql),
            $paid
        );
        $this->assertNotNull($committed, 'the money transaction closed');

        $counted = $this->firstMatch(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'UPDATE')
                && str_contains($sql, 'gratora_campaigns')
                && str_contains($sql, 'raised_cents')
        );
        $this->assertNotNull($counted, 'the campaign counter was written');

        $this->assertGreaterThan($committed, $counted, 'the counter was written after the money was committed');
    }

    public function test_the_counters_still_hold_the_right_figures(): void
    {
        [$campaignId, $donation] = $this->pendingDonation('counters-land@example.test');

        $this->service()->confirm($donation, ['gateway_txn_id' => 'txn_counters_land']);

        $campaign = Campaign::query()->where('id', $campaignId)->get();
        $this->assertSame(2500, (int) $campaign->raised_cents);
        $this->assertSame(1, (int) $campaign->donations_count);
        $this->assertSame(1, (int) $campaign->donors_count);
    }

    /**
     * @param list<string> $statements
     * @return int|null index of the first statement the matcher takes, after $from
     */
    private function firstMatch(array $statements, callable $matches, int $from = -1): ?int
    {
        foreach ($statements as $i => $sql) {
            if ($i > $from && $matches($sql)) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{0:int,1:Donation} */
    private function pendingDonation(string $email): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $campaign = Campaign::make();
        $campaign->title      = 'Aggregate campaign';
        $campaign->slug       = 'aggregate-' . uniqid();
        $campaign->status     = 'published';
        $campaign->currency   = 'USD';
        $campaign->created_at = $now;
        $campaign->updated_at = $now;
        $campaign->save();

        $donation = $this->service()->createPending(new DonationIntent(
            email: $email,
            amount_cents: 2500,
            currency: 'USD',
            gateway: 'offline',
            campaign_id: (int) $campaign->id,
        ))['donation'];

        return [(int) $campaign->id, $donation];
    }

    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    /** Straight from the database, past any model or object cache. */
    private function donationRow(int $id): object
    {
        return self::$wpdb->get_row(
            self::$wpdb->prepare('SELECT status, paid_at FROM ' . self::$prefix . 'gratora_donations WHERE id = %d', $id)
        );
    }

    /**
     * wpdb runs every statement through the `query` filter, which puts a
     * precise throw inside a transaction without editing product code.
     */
    private function whileQueryThrows(callable $matches, callable $run): void
    {
        $filter = static function ($sql) use ($matches) {
            if ($matches((string) $sql)) {
                throw new RuntimeException(self::SEAM_FAILURE);
            }
            return $sql;
        };

        add_filter('query', $filter);
        try {
            $run();
        } finally {
            remove_filter('query', $filter);
        }
    }
}
