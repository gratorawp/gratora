<?php

declare(strict_types=1);

namespace FundKit\Foundation\Upgrade;

use FundKit\Donations\Donation;
use FundKit\Receipts\Receipt;

/**
 * Puts back the receipts a partial refund voided.
 *
 * A donation that kept part of its money still has a true document to show for
 * it, and while its receipt is void the emailed download link refuses and the
 * portal hides it. Nothing else restores one: re-issuing is blocked by the
 * unique key the voided row still holds.
 *
 * @since 1.0.0
 */
final class RestoreReceiptsRetainingMoney implements UpgradeRoutine
{
    private const OPTION_CURSOR = 'fundkit_upgrade_restore_receipts_cursor';
    private const BATCH         = 200;

    /** @since 1.0.0 */
    public function id(): string
    {
        return '2026-08-25-restore-receipts-retaining-money';
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Restoring receipts that a partial refund withdrew.', 'fundkit-fundraising-campaigns');
    }

    /** @since 1.0.0 */
    public function step(): bool
    {
        $cursor = (int) get_option(self::OPTION_CURSOR, 0);

        $receipts = Receipt::query()
            ->where('voided', 1)
            ->where('id', $cursor, '>')
            ->orderBy('id', 'ASC')
            ->limit(self::BATCH)
            ->getAll();

        // Paged by id rather than by the voided flag alone: a batch that
        // selects only what it is about to fix walks the same first page every
        // time, and every receipt it decides to leave alone is in it.
        foreach ($receipts as $receipt) {
            $cursor   = (int) $receipt->id;
            $donation = Donation::query()->where('id', (int) $receipt->donation_id)->get();
            if (! $donation || (int) $donation->refunded_cents >= (int) $donation->amount_cents) {
                continue;
            }

            Receipt::query()
                ->where('id', (int) $receipt->id)
                ->update(['voided' => 0, 'voided_at' => null]);
        }

        update_option(self::OPTION_CURSOR, $cursor, false);

        if (count($receipts) < self::BATCH) {
            delete_option(self::OPTION_CURSOR);
            return true;
        }

        return false;
    }
}
