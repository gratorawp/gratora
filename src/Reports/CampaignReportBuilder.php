<?php

declare(strict_types=1);

namespace Gratora\Reports;

use Gratora\Campaigns\Styling\Tokens;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignMetricsService;
use Gratora\Foundation\Helpers\GoalProgress;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Helpers\View;
use Gratora\Receipts\OrgProfile;
use Gratora\Receipts\PdfBuilder;

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
        $currency   = Money::defaultCurrency();

        $raisedCents = (int) $summary['amount_raised_cents'];
        [$hasGoal, $goalDisplay, $percent, $barWidth] = $this->goal($campaign, $summary, $currency);

        $orgName = OrgProfile::load()['name'];

        $html = View::load('Receipts.campaign-report', [
            'accent' => Tokens::printColor((new CampaignStyleResolver())->accentFor($campaign), '#211d3f'),
            'org_name'       => $orgName,
            'campaign_title' => (string) $campaign->title,
            'range_label'    => $this->rangeLabel($range),
            'raised'         => Money::format($raisedCents, $currency),
            'has_goal'       => $hasGoal,
            'goal_display'   => $goalDisplay,
            'percent'        => $percent,
            'bar_width'      => $barWidth,
            'stats'          => [
                ['label' => __('Donations', 'gratora-donation-platform'),        'value' => number_format_i18n((int) $summary['donations_count'])],
                ['label' => __('Unique donors', 'gratora-donation-platform'),    'value' => number_format_i18n((int) $summary['donors_count'])],
                ['label' => __('Average donation', 'gratora-donation-platform'), 'value' => Money::format((int) $summary['avg_donation_cents'], $currency)],
            ],
            'generated_date' => (string) wp_date(get_option('date_format')),
        ]);

        return $this->pdf->fromHtml($html, [
            /* translators: %s: campaign title. */
            'title'   => sprintf(__('Campaign report: %s', 'gratora-donation-platform'), (string) $campaign->title),
            'author'  => $orgName,
            'subject' => __('Campaign performance report', 'gratora-donation-platform'),
        ]);
    }

    /** @since 1.0.0 */
    public static function filename(int $campaignId, string $range): string
    {
        return sprintf('gratora-campaign-%d-%s.pdf', $campaignId, $range);
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
            $percent = GoalProgress::percent((int) $summary['amount_raised_cents'], $goalCents);
            return [true, Money::format($goalCents, $currency), $percent, GoalProgress::barWidth($percent)];
        }

        $goalCount = (int) ($campaign->goal_count ?? 0);
        if ($goalCount <= 0) return [false, '', 0, 0];

        if ($type === 'donors') {
            $current = (int) $summary['donors_count'];
            /* translators: %s: donor goal count */
            $display = sprintf(__('%s donors', 'gratora-donation-platform'), number_format_i18n($goalCount));
        } else {
            $current = (int) $summary['donations_count'];
            /* translators: %s: donation goal count */
            $display = sprintf(__('%s donations', 'gratora-donation-platform'), number_format_i18n($goalCount));
        }

        $percent = GoalProgress::percent($current, $goalCount);
        return [true, $display, $percent, GoalProgress::barWidth($percent)];
    }

    /** @since 1.0.0 */
    private function rangeLabel(string $range): string
    {
        return match ($range) {
            'today'    => __('Today', 'gratora-donation-platform'),
            'last-7'   => __('Last 7 days', 'gratora-donation-platform'),
            'last-30'  => __('Last 30 days', 'gratora-donation-platform'),
            'last-90'  => __('Last 90 days', 'gratora-donation-platform'),
            'all-time' => __('All time', 'gratora-donation-platform'),
            default    => $range,
        };
    }
}
