<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Foundation\Helpers\GoalProgress;
use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
final class CampaignProgressBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/campaign-progress';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'showLabels' => ['type' => 'boolean', 'default' => true],
            'align'      => ['type' => 'string',  'default' => 'left'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $type    = $campaign->goal_type ?: 'amount';
        $current = match ($type) {
            'donations' => (int) $campaign->donations_count,
            'donors'    => (int) $campaign->donors_count,
            default     => (int) $campaign->raised_cents,
        };
        $target = match ($type) {
            'amount' => (int) ($campaign->goal_cents ?? 0),
            default  => (int) ($campaign->goal_count ?? 0),
        };
        $pct      = GoalProgress::percent($current, $target);
        $barWidth = GoalProgress::barWidth($pct);

        return View::loadRelative(__DIR__, 'views/campaign-progress', [
            'goalType'    => $type,
            'current'     => $current,
            'target'      => $target,
            'pct'         => $pct,
            'barWidth'    => $barWidth,
            'currency'    => $campaign->currency,
            'showLabels'  => (bool) ($attrs['showLabels'] ?? true),
            'align'       => in_array($attrs['align'] ?? 'left', ['left', 'center'], true)
                ? (string) $attrs['align'] : 'left',
            'styleVars' => $this->styleVars($campaign),
        ]);
    }
}
