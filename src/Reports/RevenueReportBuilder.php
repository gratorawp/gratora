<?php

declare(strict_types=1);

namespace Dono\Reports;

use Dono\Exports\RevenueExporter;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;
use Dono\Receipts\PdfBuilder;

/**
 * Builds a one-page revenue summary for a calendar year: the year's total, a
 * month-by-month table, and the best month. Aggregate figures only, no donor
 * PII, so it can be handed to a board or an auditor as it stands.
 *
 * @version 1.0.0
 */
final class RevenueReportBuilder
{
    public function __construct(
        private PdfBuilder $pdf,
        private RevenueExporter $revenue,
    ) {
    }

    public function build(int $year): string
    {
        $currency = Money::defaultCurrency();
        $series   = $this->revenue->series(sprintf('%04d-01', $year), sprintf('%04d-12', $year));

        $totalCents = 0;
        $totalCount = 0;
        $best       = null;
        $months     = [];

        foreach ($series as $row) {
            $totalCents += $row['amount_cents'];
            $totalCount += $row['donations_count'];

            if ($best === null || $row['amount_cents'] > $best['amount_cents']) {
                $best = $row;
            }

            $months[] = [
                'label'  => $this->monthLabel($row['month']),
                'count'  => number_format_i18n($row['donations_count']),
                'amount' => Money::format($row['amount_cents'], $currency),
            ];
        }

        $org     = get_option('dono_org_profile', []);
        $orgName = trim((string) (is_array($org) ? ($org['name'] ?? '') : '')) ?: (string) get_bloginfo('name');

        $html = View::load('Receipts.revenue-report', [
            'org_name'       => $orgName,
            'year'           => (string) $year,
            'total'          => Money::format($totalCents, $currency),
            'months'         => $months,
            'stats'          => [
                ['label' => __('Donations', 'dono'),        'value' => number_format_i18n($totalCount)],
                ['label' => __('Average donation', 'dono'), 'value' => Money::format($totalCount > 0 ? intdiv($totalCents, $totalCount) : 0, $currency)],
                ['label' => __('Best month', 'dono'),       'value' => $best !== null && $best['amount_cents'] > 0 ? $this->monthLabel($best['month']) : '-'],
            ],
            'generated_date' => (string) wp_date(get_option('date_format')),
        ]);

        return $this->pdf->fromHtml($html, [
            /* translators: %s: four-digit year. */
            'title'   => sprintf(__('Revenue report %s', 'dono'), (string) $year),
            'author'  => $orgName,
            'subject' => __('Revenue and donations report', 'dono'),
        ]);
    }

    public static function filename(int $year): string
    {
        return sprintf('dono-revenue-%d.pdf', $year);
    }

    /** "2026-03" to a localised "March", falling back to the raw key. */
    private function monthLabel(string $month): string
    {
        $ts = strtotime($month . '-01 12:00:00');

        return $ts === false ? $month : (string) wp_date('F', $ts);
    }
}
