<?php

declare(strict_types=1);

namespace Dono\GiftAid;

use Dono\Donations\Donation;
use Dono\Foundation\Helpers\Csv;
use Dono\Foundation\Helpers\Money;

/**
 * The claim file a charity submits to HMRC.
 *
 * Column order and headings follow HMRC's Gift Aid schedule spreadsheet, which
 * is what Charities Online accepts. Rows are one per donation; aggregation of
 * small gifts is a separate mechanism and deliberately out of scope here, so
 * the aggregation column is always blank.
 *
 * Refunded gifts are excluded: money returned to the donor was never a gift,
 * and claiming on it is a claim the charity would have to repay.
 *
 * @version 1.0.0
 */
final class GiftAidClaimExport
{
    /** HMRC's schedule spreadsheet headings, in its order. */
    public const COLUMNS = [
        'Title',
        'First name',
        'Last name',
        'House name or number',
        'Postcode',
        'Aggregated donations',
        'Sponsored event',
        'Donation date',
        'Amount',
    ];

    public function __construct(private GiftAidClaims $claims)
    {
    }

    /**
     * @return array{csv:string, rows:int, skipped:int, amount_cents:int}
     */
    public function build(string $from, string $to): array
    {
        $stream = fopen('php://temp', 'r+');
        Csv::writeRow($stream, self::COLUMNS);

        $rows = 0;
        $skipped = 0;
        $amount = 0;

        foreach ($this->claimable($from, $to) as $donation) {
            $claim = $this->claims->read($donation);

            // An incomplete record is left out rather than sent half-filled:
            // HMRC rejects the whole schedule on a bad row, and the charity
            // would rather chase the donor than have the claim bounced.
            if ($claim === null || ! $this->claims->isComplete($donation)) {
                $skipped++;
                continue;
            }

            $net = (int) $donation->amount_cents - (int) ($donation->refunded_cents ?? 0);
            if ($net <= 0) {
                $skipped++;
                continue;
            }

            Csv::writeRow($stream, [
                $claim['title'],
                $claim['first_name'],
                $claim['last_name'],
                $claim['house'],
                $claim['postcode'],
                '',
                '',
                gmdate('d/m/y', strtotime((string) ($donation->paid_at ?? $donation->created_at))),
                number_format($net / 100, 2, '.', ''),
            ]);

            $rows++;
            $amount += $net;
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return ['csv' => $csv, 'rows' => $rows, 'skipped' => $skipped, 'amount_cents' => $amount];
    }

    /** What HMRC would repay on the gifts in this file, at the basic rate. */
    public static function reclaimCents(int $donatedCents): int
    {
        // 20% basic rate: the donor gave from taxed income, so the gross gift
        // is amount / 0.8 and the charity reclaims the difference. That is 25%
        // of the amount received, which is why the donor-facing copy says 25%.
        return (int) round($donatedCents * 0.25);
    }

    /** @return list<Donation> */
    private function claimable(string $from, string $to): array
    {
        return Donation::query()
            ->where('gift_aid', 1)
            ->where('status', 'paid')
            ->where('is_test', 0)
            ->where('paid_at', $from, '>=')
            ->where('paid_at', $to, '<=')
            ->orderBy('paid_at')
            ->getAll();
    }

    public static function formatAmount(int $cents): string
    {
        return Money::format($cents, GiftAidEligibility::CURRENCY);
    }
}
