<?php

declare(strict_types=1);

namespace Dono\Receipts;

/**
 * Receipt query helpers.
 *
 * @version 1.0.0
 */
final class ReceiptRepository
{
    public function findById(int $id): ?Receipt
    {
        return Receipt::query()->find('id', $id);
    }

    /** One receipt per (donation, renderer); the idempotency anchor for re-issues. */
    public function findFor(int $donationId, string $rendererId): ?Receipt
    {
        return Receipt::query()
            ->where('donation_id', $donationId)
            ->where('renderer_id', $rendererId)
            ->get();
    }

    /** @return array<Receipt> */
    public function forDonation(int $donationId): array
    {
        return Receipt::query()->where('donation_id', $donationId)->getAll();
    }

    /** @return array<Receipt> */
    public function forDonor(int $donorId, int $limit = 100): array
    {
        return Receipt::query()
            ->where('donor_id', $donorId)
            ->orderBy('issued_at', 'DESC')
            ->limit($limit)
            ->getAll();
    }
}
