<?php

declare(strict_types=1);

namespace Dono\Exports;

use DateTimeImmutable;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Helpers\Csv;
use Dono\Foundation\Helpers\Money;

/**
 * Month-by-month revenue and donation counts as CSV.
 *
 * Months with no donations are written as zero rows rather than skipped, so the
 * file charts as a continuous series and a quiet month is visible instead of
 * absent.
 *
 * @since 1.0.0
 */
final class RevenueExporter
{
    /** Months per file. Twenty years of monthly rows is already generous. */
    private const MAX_MONTHS = 240;

    /** @since 1.0.0 */
    public function __construct(private DonationRepository $donations)
    {
    }

    /**
     * @return list<array{month:string,amount_cents:int,donations_count:int}>
     * @since 1.0.0
     */
    public function series(string $fromMonth, string $toMonth): array
    {
        [$start, $end] = $this->bounds($fromMonth, $toMonth);

        // Plain dates: the repository reads them as the org's calendar days and
        // buckets by the same, so a December donation given in the evening is
        // in the December row here and on the donor's statement both.
        $rows = $this->donations->dailyPaidBetween(
            $start->format('Y-m-d'),
            $end->format('Y-m-t')
        );

        $byMonth = [];
        foreach ($rows as $r) {
            $key = substr((string) $r['day'], 0, 7);
            if (! isset($byMonth[$key])) {
                $byMonth[$key] = ['amount' => 0, 'count' => 0];
            }
            $byMonth[$key]['amount'] += (int) $r['amount_cents'];
            $byMonth[$key]['count']  += (int) $r['donations_count'];
        }

        $series = [];
        $cursor = $start;
        while ($cursor <= $end && count($series) < self::MAX_MONTHS) {
            $key      = $cursor->format('Y-m');
            $series[] = [
                'month'           => $key,
                'amount_cents'    => $byMonth[$key]['amount'] ?? 0,
                'donations_count' => $byMonth[$key]['count']  ?? 0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $series;
    }

    /** @since 1.0.0 */
    public function toCsv(string $fromMonth, string $toMonth): string
    {
        $currency = Money::defaultCurrency();

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }

        fwrite($out, "\xEF\xBB\xBF");
        Csv::writeRow($out, [
            __('Month', 'dono-fundraising-platform'),
            __('Donations', 'dono-fundraising-platform'),
            /* translators: %s: currency code, e.g. EUR. */
            sprintf(__('Revenue (%s)', 'dono-fundraising-platform'), $currency),
            /* translators: %s: currency code, e.g. EUR. */
            sprintf(__('Average donation (%s)', 'dono-fundraising-platform'), $currency),
        ]);

        foreach ($this->series($fromMonth, $toMonth) as $row) {
            $count = $row['donations_count'];
            Csv::writeRow($out, [
                $row['month'],
                (string) $count,
                number_format($row['amount_cents'] / 100, 2, '.', ''),
                // Zero rather than a division by zero in a month with nothing.
                number_format($count > 0 ? ($row['amount_cents'] / $count) / 100 : 0, 2, '.', ''),
            ]);
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @since 1.0.0 */
    public static function filename(string $fromMonth, string $toMonth): string
    {
        return sprintf('revenue-%s-to-%s.csv', $fromMonth, $toMonth);
    }

    /**
     * Both ends normalized to the first of their month, swapped if reversed so
     * a backwards range returns that range rather than nothing.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     * @since 1.0.0
     */
    private function bounds(string $fromMonth, string $toMonth): array
    {
        $start = $this->month($fromMonth) ?? $this->month((string) wp_date('Y-01'));
        $end   = $this->month($toMonth)   ?? $this->month((string) wp_date('Y-m'));

        return $start <= $end ? [$start, $end] : [$end, $start];
    }

    /** @since 1.0.0 */
    private function month(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $m[1], $month));
    }
}
