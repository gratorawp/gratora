<?php

declare(strict_types=1);

namespace FundKit\Foundation\Maintenance;

use FundKit\Async\AsyncDispatcher;
use FundKit\Foundation\Batch\BatchProcessor;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Time\Clock;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\SettlesOutOfBand;
use FundKit\Vendor\Queryable\DB;

/**
 * Retire donations that were begun and never paid.
 *
 * A row is written before the gateway is contacted, so every abandoned
 * checkout leaves one, and nothing ever closed them. They are not only clutter:
 * DonationQueries::supersededIds correlates a JSON check against the pending
 * set on every admin list, export and KPI query, and its cost was measured
 * against the number of checkouts real donors abandon. That quantity is one an
 * unauthenticated caller decides.
 *
 * They are marked, never deleted. An abandoned checkout is a fact about a
 * campaign, and the reference has already been issued.
 *
 * @since 1.0.0
 */
final class AbandonedPendingReaper
{
    public const HOOK = 'fundkit.cron.abandon_pending';

    private const DAILY = 86400;
    private const BATCH = 500;

    /** Allow ample time for open checkouts and delayed gateway responses. */
    private const AFTER_DAYS = 30;

    /** @since 1.0.0 */
    public function __construct(private AsyncDispatcher $async, private Clock $clock)
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', fn () => $this->async->scheduleRecurring(self::HOOK, self::DAILY));
    }

    /**
     * Gateways whose pending rows are the record of money still coming.
     *
     * For an out-of-band gateway the pending row IS the queue entry an
     * incoming transfer is matched against, and the donor is quoting its
     * reference. Closing one would strand a bank transfer that is simply
     * slower than this window, so they are never swept. Asked of the registry
     * rather than a list kept here, so a gateway registered through
     * fundkit.gateways.register is covered by implementing the interface.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    private function outOfBandGateways(): array
    {
        $ids = [];
        foreach (Plugin::instance()->container->get(GatewayManager::class)->all() as $id => $gateway) {
            if ($gateway instanceof SettlesOutOfBand) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Share the abandonment threshold with the donor delete gate.
     *
     * @since 1.0.0
     */
    public static function abandonAfterDays(): int
    {
        return max(1, (int) apply_filters('fundkit.donations.abandon_after_days', self::AFTER_DAYS));
    }

    /** @since 1.0.0 */
    public function run(): void
    {
        $days = self::abandonAfterDays();
        $before = $this->clock->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        $skip = $this->outOfBandGateways();

        $more = BatchProcessor::step(
            function (int $n) use ($before, $skip): array {
                $query = DB::table('fundkit_donations')
                    ->select('id')
                    ->where('status', 'pending')
                    ->where('created_at', $before, '<')
                    // Money that moved leaves a transaction id and a paid_at
                    // behind even when the row never reached paid, and a row
                    // carrying either is a reconciliation question, not litter.
                    ->whereIsNull('paid_at')
                    ->whereIsNull('gateway_txn_id');

                if ($skip !== []) {
                    $query = $query->whereNotIn('gateway', $skip);
                }

                return $query->limit($n)->getAll();
            },
            function (array $rows): void {
                $ids = array_values(array_filter(array_map(
                    static fn (array $row): int => (int) ($row['id'] ?? 0),
                    $rows
                )));
                if ($ids === []) {
                    return;
                }

                // Transitioned in place rather than through markFailed: this is
                // a sweep, not a payment event, and firing donation.failed once
                // per row would tell every listener that a backlog of old
                // checkouts had just failed together. The status condition is
                // repeated here so a row that reached paid between the read and
                // this write is never overwritten.
                DB::table('fundkit_donations')
                    ->whereIn('id', $ids)
                    ->where('status', 'pending')
                    ->update([
                        'status'         => 'failed',
                        'failure_reason' => 'Abandoned before payment.',
                        // An attempt that never paid never earns the
                        // reactivation it was carrying, and the address it
                        // holds belongs to someone who asked to be erased. It
                        // goes with the attempt.
                        'pending_reactivation_email' => null,
                        'updated_at'     => $this->clock->now()->format('Y-m-d H:i:s'),
                    ]);
            },
            self::BATCH,
            false
        );

        if ($more) {
            $this->async->enqueue(self::HOOK);
        }
    }
}
