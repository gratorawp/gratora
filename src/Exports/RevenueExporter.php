<?php

declare(strict_types=1);

namespace Gratora\Exports;

use DateTimeImmutable;
use Gratora\Donations\DonationRepository;
use Gratora\Foundation\Helpers\Csv;
use Gratora\Foundation\Helpers\Money;

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
        [$start, $end] = self::bounds($fromMonth, $toMonth);

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
        while ($cursor <= $end) {
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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://temp stream, not a filesystem path; WP_Filesystem has no streaming equivalent.
        fwrite($out, "\xEF\xBB\xBF");
        Csv::writeRow($out, [
            __('Month', 'gratora-donation-platform'),
            __('Donations', 'gratora-donation-platform'),
            /* translators: %s: currency code, e.g. EUR. */
            sprintf(__('Revenue (%s)', 'gratora-donation-platform'), $currency),
            /* translators: %s: currency code, e.g. EUR. */
            sprintf(__('Average donation (%s)', 'gratora-donation-platform'), $currency),
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
        // The range the file holds, not the one that was asked for: past the
        // cap they differ, and a name that claims months the CSV does not
        // carry is what an operator files and later reads back.
        [$start, $end] = self::bounds($fromMonth, $toMonth);

        return sprintf('revenue-%s-to-%s.csv', $start->format('Y-m'), $end->format('Y-m'));
    }

    /**
     * Both ends normalized to the first of their month, swapped if reversed so
     * a backwards range returns that range rather than nothing.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     * @since 1.0.0
     */
    private static function bounds(string $fromMonth, string $toMonth): array
    {
        $start = self::month($fromMonth) ?? self::month((string) wp_date('Y-01'));
        $end   = self::month($toMonth)   ?? self::month((string) wp_date('Y-m'));

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // Trimmed from the old end. A range past the cap has to lose the months
        // furthest from the question being asked, not the ones the operator
        // opened the export for.
        $earliest = $end->modify('-' . (self::MAX_MONTHS - 1) . ' months');

        return [$start < $earliest ? $earliest : $start, $end];
    }

    /** @since 1.0.0 */
    private static function month(string $value): ?DateTimeImmutable
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
