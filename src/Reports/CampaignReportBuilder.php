<?php

declare(strict_types=1);

namespace Dono\Reports;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignMetricsService;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;
use Dono\Receipts\PdfBuilder;

/**
 * Builds a single-page campaign performance PDF: raised vs goal, a progress bar,
 * and a small stats row. Aggregate figures only, no donor PII.
 *
 * @since 1.0.0
 */
final class CampaignReportBuilder
{
    /** @since 1.0.0 */
    public function __construct(
        private PdfBuilder $pdf,
        private CampaignMetricsService $metrics,
    ) {
    }

    /** @since 1.0.0 */
    public function build(Campaign $campaign, string $range): string
    {
        $campaignId = (int) $campaign->id;
        $summary    = $this->metrics->summary($campaignId, $range);
        $currency   = (string) $campaign->currency;

        $raisedCents = (int) $summary['amount_raised_cents'];
        [$hasGoal, $goalDisplay, $percent, $barWidth] = $this->goal($campaign, $summary, $currency);

        $org     = get_option('dono_org_profile', []);
        $orgName = trim((string) (is_array($org) ? ($org['name'] ?? '') : '')) ?: (string) get_bloginfo('name');

        $html = View::load('Receipts.campaign-report', [
            'org_name'       => $orgName,
            'campaign_title' => (string) $campaign->title,
            'range_label'    => $this->rangeLabel($range),
            'raised'         => Money::format($raisedCents, $currency),
            'has_goal'       => $hasGoal,
            'goal_display'   => $goalDisplay,
            'percent'        => $percent,
            'bar_width'      => $barWidth,
            'stats'          => [
                ['label' => __('Donations', 'dono-fundraising-platform'),        'value' => number_format_i18n((int) $summary['donations_count'])],
                ['label' => __('Unique donors', 'dono-fundraising-platform'),    'value' => number_format_i18n((int) $summary['donors_count'])],
                ['label' => __('Average donation', 'dono-fundraising-platform'), 'value' => Money::format((int) $summary['avg_donation_cents'], $currency)],
            ],
            'generated_date' => (string) wp_date(get_option('date_format')),
        ]);

        return $this->pdf->fromHtml($html, [
            /* translators: %s: campaign title. */
            'title'   => sprintf(__('Campaign report: %s', 'dono-fundraising-platform'), (string) $campaign->title),
            'author'  => $orgName,
            'subject' => __('Campaign performance report', 'dono-fundraising-platform'),
        ]);
    }

    /**
     * Stable download filename shared by the command link and the streaming route.
     *
     * @since 1.0.0
     */
    public static function filename(int $campaignId, string $range): string
    {
        return sprintf('dono-campaign-%d-%s.pdf', $campaignId, $range);
    }

    /**
     * Progress against the campaign's goal. Handles amount goals (money bar) and
     * count goals (donations/donors), and returns "no goal" when none is set.
     *
     * @param array<string,mixed> $summary
     * @return array{0:bool,1:string,2:int,3:int} [has_goal, goal_display, percent, bar_width]
     *
     * @since 1.0.0
     */
    private function goal(Campaign $campaign, array $summary, string $currency): array
    {
        $type = (string) $campaign->goal_type;

        if ($type === 'amount') {
            $goalCents = (int) ($campaign->goal_cents ?? 0);
            if ($goalCents <= 0) return [false, '', 0, 0];
            $percent = (int) round(((int) $summary['amount_raised_cents'] / $goalCents) * 100);
            return [true, Money::format($goalCents, $currency), $percent, $this->clamp($percent)];
        }

        $goalCount = (int) ($campaign->goal_count ?? 0);
        if ($goalCount <= 0) return [false, '', 0, 0];

        if ($type === 'donors') {
            $current = (int) $summary['donors_count'];
            /* translators: %s: donor goal count */
            $display = sprintf(__('%s donors', 'dono-fundraising-platform'), number_format_i18n($goalCount));
        } else {
            $current = (int) $summary['donations_count'];
            /* translators: %s: donation goal count */
            $display = sprintf(__('%s donations', 'dono-fundraising-platform'), number_format_i18n($goalCount));
        }

        $percent = (int) round(($current / $goalCount) * 100);
        return [true, $display, $percent, $this->clamp($percent)];
    }

    /** @since 1.0.0 */
    private function clamp(int $percent): int
    {
        return max(0, min(100, $percent));
    }

    /** @since 1.0.0 */
    private function rangeLabel(string $range): string
    {
        return match ($range) {
            'today'    => __('Today', 'dono-fundraising-platform'),
            'last-7'   => __('Last 7 days', 'dono-fundraising-platform'),
            'last-30'  => __('Last 30 days', 'dono-fundraising-platform'),
            'last-90'  => __('Last 90 days', 'dono-fundraising-platform'),
            'all-time' => __('All time', 'dono-fundraising-platform'),
            default    => $range,
        };
    }
}
