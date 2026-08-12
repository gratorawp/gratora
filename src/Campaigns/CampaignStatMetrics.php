<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Donations\DonationRepository;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Time\Clock;

/**
 * A metric answers null when the campaign cannot support it, so a stat block
 * placed on a campaign it does not suit renders nothing instead of a confident
 * zero.
 *
 * @since 1.0.0
 */
final class CampaignStatMetrics
{
    /** @var array<int,int> campaign id => largest net paid gift, in cents */
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
            'raised'    => __('Amount raised', 'dono-fundraising-platform'),
            'goal'      => __('Our goal', 'dono-fundraising-platform'),
            'remaining' => __('Still needed', 'dono-fundraising-platform'),
            'percent'   => __('Of goal reached', 'dono-fundraising-platform'),
            'donations' => __('Donations', 'dono-fundraising-platform'),
            'donors'    => __('Donors', 'dono-fundraising-platform'),
            'average'   => __('Average donation', 'dono-fundraising-platform'),
            'top'       => __('Top donation', 'dono-fundraising-platform'),
            'days_left' => __('Days left', 'dono-fundraising-platform'),
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
        $goal      = (int) $campaign->goal_cents;
        $donations = (int) $campaign->donations_count;

        return match ($metric) {
            'raised'    => Money::format($raised, $currency),
            'goal'      => $goal > 0 ? Money::format($goal, $currency) : null,
            'remaining' => $goal > 0 ? Money::format(max(0, $goal - $raised), $currency) : null,
            'percent'   => $goal > 0
                ? sprintf('%d%%', (int) min(100, floor(($raised / $goal) * 100)))
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
