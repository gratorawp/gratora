<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\CampaignStatMetrics;
use Dono\Foundation\Helpers\View;

/**
 * One metric per block rather than a group with toggles: a group moves as a
 * unit, forcing every figure into the same column and order.
 *
 * Renders nothing when the metric does not apply to this campaign. See
 * CampaignStatMetrics for why that is silence rather than a zero.
 *
 * @since 1.0.0
 */
final class CampaignStatBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function __construct(
        CampaignRepository $campaigns,
        private readonly CampaignStatMetrics $metrics,
    ) {
        parent::__construct($campaigns);
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/campaign-stat';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'metric' => ['type' => 'string',  'default' => 'raised'],
            'label'  => ['type' => 'string',  'default' => ''],
            'size'   => ['type' => 'string',  'default' => 'sm'],
            'align'  => ['type' => 'string',  'default' => 'left'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $metric = (string) ($attrs['metric'] ?? 'raised');
        if (! CampaignStatMetrics::isKey($metric)) {
            $metric = 'raised';
        }

        $value = $this->metrics->value($campaign, $metric);
        if ($value === null) {
            return '';
        }

        return View::loadRelative(__DIR__, 'views/campaign-stat', [
            'metric'    => $metric,
            'value'     => $value,
            'label'     => $this->metrics->label($metric, (string) ($attrs['label'] ?? '')),
            'size'      => in_array($attrs['size'] ?? 'sm', ['sm', 'md', 'lg'], true)
                ? (string) $attrs['size'] : 'sm',
            'align'     => in_array($attrs['align'] ?? 'left', ['left', 'center', 'right'], true)
                ? (string) $attrs['align'] : 'left',
            'styleVars' => $this->styleVars($campaign),
        ]);
    }
}
