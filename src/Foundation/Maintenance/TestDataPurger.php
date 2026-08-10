<?php

declare(strict_types=1);

namespace Dono\Foundation\Maintenance;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Recurring\RecurringPlan;
use Dono\Vendor\Queryable\DB;

/**
 * Removes everything a test-mode gateway left behind, so a site can go live on
 * a clean ledger.
 *
 * Test rows are already invisible to every total: donationsOnly() filters
 * is_test, so nothing here changes a figure anyone has seen. What it changes is
 * the ledger you read by eye, which is the point of running it before launch.
 *
 * Deliberately narrow. It deletes test donations, the rows that describe them,
 * test recurring plans, and donors that nothing refers to afterwards. A donor
 * who also gave for real keeps their row, decided by the same rule the Donors
 * screen uses, add-on vetoes included.
 *
 * @version 1.0.0
 */
final class TestDataPurger
{
    /** Bounds each statement; a long-lived sandbox can hold a lot of these. */
    private const CHUNK = 500;

    public function __construct(private DonorService $donors)
    {
    }

    /**
     * What a purge would remove, without removing it.
     *
     * @return array{donations:int, recurring_plans:int, donors:int}
     */
    public function preview(): array
    {
        $donationIds = $this->testDonationIds();

        return [
            'donations'       => count($donationIds),
            'recurring_plans' => (int) RecurringPlan::query()->where('is_test', 1)->count(),
            'donors'          => count($this->donorsLeftWithNothing($this->donorIdsBehind($donationIds))),
        ];
    }

    /**
     * @return array{donations:int, recurring_plans:int, donors:int}
     */
    public function purge(): array
    {
        $donationIds = $this->testDonationIds();
        $donorIds    = $this->donorIdsBehind($donationIds);

        $removed = ['donations' => 0, 'recurring_plans' => 0, 'donors' => 0];

        foreach (array_chunk($donationIds, self::CHUNK) as $chunk) {
            // Add-ons hang their own rows off a donation (ticket orders, gift
            // aid claims, tributes). Core cannot know them, and orphaning them
            // would be worse than leaving them, so they are told before the
            // rows they point at disappear.
            do_action('dono.test_data.purge_donations', $chunk);

            DB::table('dono_receipts')->whereIn('donation_id', $chunk)->delete();
            DB::table('dono_refunds')->whereIn('donation_id', $chunk)->delete();
            DB::table('dono_donation_notes')->whereIn('donation_id', $chunk)->delete();
            DB::table('dono_events')->whereIn('donation_id', $chunk)->delete();

            $removed['donations'] += (int) Donation::query()->whereIn('id', $chunk)->delete()->affectedRows;
        }

        $planIds = array_map('intval', array_column(
            RecurringPlan::query()->where('is_test', 1)->selectRaw('id')->getAll(),
            'id'
        ));
        foreach (array_chunk($planIds, self::CHUNK) as $chunk) {
            do_action('dono.test_data.purge_plans', $chunk);

            DB::table('dono_events')->whereIn('recurring_plan_id', $chunk)->delete();
            $removed['recurring_plans'] += (int) RecurringPlan::query()->whereIn('id', $chunk)->delete()->affectedRows;
        }

        // Donors last: whether one can go is only knowable once the donations
        // and plans behind it are gone.
        foreach ($this->donorsLeftWithNothing($donorIds) as $donorId) {
            $donor = Donor::query()->where('id', $donorId)->get();
            if (! $donor instanceof Donor) {
                continue;
            }

            try {
                $this->donors->delete($donor);
                $removed['donors']++;
            } catch (\Throwable $e) {
                // Something still refers to them, an add-on veto most likely.
                // Their row is harmless; losing the whole purge over it is not.
                continue;
            }
        }

        return $removed;
    }

    /** @return array<int> */
    private function testDonationIds(): array
    {
        return array_map('intval', array_column(
            Donation::query()->where('is_test', 1)->selectRaw('id')->getAll(),
            'id'
        ));
    }

    /**
     * @param  array<int> $donationIds
     * @return array<int>
     */
    private function donorIdsBehind(array $donationIds): array
    {
        if ($donationIds === []) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($donationIds, self::CHUNK) as $chunk) {
            $rows = Donation::query()
                ->whereIn('id', $chunk)
                ->selectRaw('DISTINCT donor_id')
                ->getAll();
            foreach ($rows as $row) {
                $id = (int) ($row['donor_id'] ?? 0);
                if ($id > 0) $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Donors with nothing live behind them, which is the same set before and
     * after the purge: asking for live rows rather than excluding the doomed
     * ids keeps this off an IN list thousands of entries long.
     *
     * @param  array<int> $donorIds
     * @return array<int>
     */
    private function donorsLeftWithNothing(array $donorIds): array
    {
        $out = [];
        foreach ($donorIds as $donorId) {
            $hasLiveDonation = Donation::query()
                ->where('donor_id', $donorId)
                ->where('is_test', 0)
                ->exists();
            if ($hasLiveDonation) {
                continue;
            }

            $hasLivePlan = RecurringPlan::query()
                ->where('donor_id', $donorId)
                ->where('is_test', 0)
                ->exists();
            if ($hasLivePlan) {
                continue;
            }

            $out[] = $donorId;
        }

        return $out;
    }
}
