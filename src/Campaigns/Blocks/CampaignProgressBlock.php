<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Renders the campaign fundraising progress bar.
 *
 * @version 1.0.0
 */
final class CampaignProgressBlock extends CampaignBlock
{
    public function name(): string
    {
        return 'dono/campaign-progress';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'showLabels' => ['type' => 'boolean', 'default' => true],
            'align'      => ['type' => 'string',  'default' => 'left'],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice();

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
        $pct = $target > 0 ? min(100, (int) round(($current / $target) * 100)) : 0;

        return View::loadRelative(__DIR__, 'views/campaign-progress', [
            'goalType'    => $type,
            'current'     => $current,
            'target'      => $target,
            'pct'         => $pct,
            'currency'    => $campaign->currency,
            'showLabels'  => (bool) ($attrs['showLabels'] ?? true),
            'align'       => in_array($attrs['align'] ?? 'left', ['left', 'center'], true)
                ? (string) $attrs['align'] : 'left',
            'themePrimary' => $campaign->accentColor(),
        ]);
    }
}
