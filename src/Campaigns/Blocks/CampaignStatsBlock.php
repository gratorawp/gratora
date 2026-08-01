<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Renders campaign summary stats (raised, donations, donors).
 *
 * @version 1.0.0
 */
final class CampaignStatsBlock extends CampaignBlock
{
    public function name(): string
    {
        return 'dono/campaign-stats';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'showRaised'    => ['type' => 'boolean', 'default' => true],
            'showDonations' => ['type' => 'boolean', 'default' => true],
            'showDonors'    => ['type' => 'boolean', 'default' => true],
            'align'         => ['type' => 'string',  'default' => 'left'],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice();

        return View::loadRelative(__DIR__, 'views/campaign-stats', [
            'raisedCents'    => (int) $campaign->raised_cents,
            'donationsCount' => (int) $campaign->donations_count,
            'donorsCount'    => (int) $campaign->donors_count,
            'currency'       => $campaign->currency,
            'showRaised'     => (bool) ($attrs['showRaised']    ?? true),
            'showDonations'  => (bool) ($attrs['showDonations'] ?? true),
            'showDonors'     => (bool) ($attrs['showDonors']    ?? true),
            'align'          => in_array($attrs['align'] ?? 'left', ['left', 'center', 'right'], true)
                ? (string) $attrs['align'] : 'left',
            'styleVars'      => $this->styleVars($campaign),
        ]);
    }
}
