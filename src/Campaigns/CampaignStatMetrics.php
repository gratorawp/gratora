<?php

declare(strict_types=1);

namespace FundKit\Campaigns;

use FundKit\Donations\DonationRepository;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\Time\Clock;

/**
 * A metric answers null when the campaign cannot support it, so a stat block
 * placed on a campaign it does not suit renders nothing instead of a confident
 * zero.
 *
 * @since 1.0.0
 */
final class CampaignStatMetrics
{
    /** @var array<int,int> campaign id => largest net paid donation, in cents */
    private array $topCache = [];

    /** @since 1.0.0 */
    public function __construct(
        private readonly DonationRepository $donations,
        private readonly Clock $clock,
    ) {
    }

    /** @since 1.0.0 */
    public static function labels(): array
    {
        return [
            'raised'    => __('Amount raised', 'fundkit-fundraising-campaigns'),
            'goal'      => __('Our goal', 'fundkit-fundraising-campaigns'),
            'remaining' => __('Still needed', 'fundkit-fundraising-campaigns'),
            'percent'   => __('Of goal reached', 'fundkit-fundraising-campaigns'),
            'donations' => __('Donations', 'fundkit-fundraising-campaigns'),
            'donors'    => __('Donors', 'fundkit-fundraising-campaigns'),
            'average'   => __('Average donation', 'fundkit-fundraising-campaigns'),
            'top'       => __('Top donation', 'fundkit-fundraising-campaigns'),
            'days_left' => __('Days left', 'fundkit-fundraising-campaigns'),
        ];
    }

    /** @since 1.0.0 */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    /** @since 1.0.0 */
    public static function isKey(string $metric): bool
    {
        return array_key_exists($metric, self::labels());
    }

    /** @since 1.0.0 */
    public function value(Campaign $campaign, string $metric): ?string
    {
        $currency  = (string) $campaign->currency;
        $raised    = (int) $campaign->raised_cents;
        $donations = (int) $campaign->donations_count;

        $type = (string) ($campaign->goal_type ?: 'amount');
        if (! in_array($type, ['amount', 'donations', 'donors'], true)) {
            $type = 'amount';
        }

        $target  = $type === 'amount' ? (int) $campaign->goal_cents : (int) $campaign->goal_count;
        $towards = match ($type) {
            'donations' => $donations,
            'donors'    => (int) $campaign->donors_count,
            default     => $raised,
        };
        $inGoalUnits = fn (int $value): string => $type === 'amount'
            ? Money::format($value, $currency)
            : number_format_i18n($value);

        return match ($metric) {
            'raised'    => Money::format($raised, $currency),
            'goal'      => $target > 0 ? $inGoalUnits($target) : null,
            'remaining' => $target > 0 ? $inGoalUnits(max(0, $target - $towards)) : null,
            'percent'   => $target > 0
                ? sprintf('%d%%', (int) min(100, floor(($towards / $target) * 100)))
                : null,
            'donations' => number_format_i18n($donations),
            'donors'    => number_format_i18n((int) $campaign->donors_count),
            // Integer division on purpose. An average is a summary, and cents
            // of one put a false precision on it.
            'average'   => $donations > 0
                ? Money::format((int) round($raised / $donations), $currency)
                : null,
            'top'       => $this->topOrNull($campaign, $currency),
            'days_left' => $this->daysLeftOrNull($campaign),
            default     => null,
        };
    }

    /** @since 1.0.0 */
    public function label(string $metric, string $custom = ''): string
    {
        $custom = trim($custom);
        return $custom !== '' ? $custom : (self::labels()[$metric] ?? '');
    }

    /**
     * Gated on the query, not on donations_count: a drifted counter would hide
     * the figure silently. Memoized so several stat blocks cost one aggregate.
     *
     * @since 1.0.0
     */
    private function topOrNull(Campaign $campaign, string $currency): ?string
    {
        $id = (int) $campaign->id;
        $this->topCache[$id] ??= $this->donations->maxNetPaidAmount($id);

        return $this->topCache[$id] > 0 ? Money::format($this->topCache[$id], $currency) : null;
    }

    /**
     * An ended campaign reads zero rather than a negative count.
     *
     * @since 1.0.0
     */
    private function daysLeftOrNull(Campaign $campaign): ?string
    {
        if (empty($campaign->ends_at)) {
            return null;
        }

        $end = new \DateTimeImmutable((string) $campaign->ends_at);
        $now = $this->clock->now();

        return number_format_i18n($end < $now ? 0 : (int) $now->diff($end)->format('%a'));
    }
}
