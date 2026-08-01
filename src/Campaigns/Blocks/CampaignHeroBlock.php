<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;

/**
 * Renders the campaign hero: title + description overlaid on the cover image
 * with a gradient scrim, plus an optional raised-of-goal summary.
 *
 * @version 1.1.0
 */
final class CampaignHeroBlock extends CampaignBlock
{
    public function name(): string
    {
        return 'dono/campaign-hero';
    }

    public function attributes(): array
    {
        return $this->campaignIdAttr() + [
            'showDescription' => ['type' => 'boolean', 'default' => true],
            'showCover'       => ['type' => 'boolean', 'default' => true],
            'showSummary'     => ['type' => 'boolean', 'default' => true],
            'showTitle'       => ['type' => 'boolean', 'default' => true],
            'headingLevel'    => ['type' => 'integer', 'default' => 1],
            'align'           => ['type' => 'string',  'default' => 'left'],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $campaign = $this->resolveCampaign($attrs);
        if (! $campaign) return $this->notBoundNotice($attrs);

        $imageUrl = $campaign->image_attachment_id
            ? wp_get_attachment_image_url($campaign->image_attachment_id, 'large')
            : null;

        $goalCents = $campaign->goal_type === 'amount' ? (int) $campaign->goal_cents : 0;

        return View::loadRelative(__DIR__, 'views/campaign-hero', [
            'title'           => $campaign->title,
            'description'     => $campaign->description,
            'imageUrl'        => $imageUrl,
            'showDescription' => (bool) ($attrs['showDescription'] ?? true),
            'showCover'       => (bool) ($attrs['showCover'] ?? true) && $imageUrl,
            // Not gated on having raised something. A campaign on day one showed
            // no money line and no goal, so the hero made no ask at the one
            // moment it has nothing else to show.
            'showSummary'     => (bool) ($attrs['showSummary'] ?? true),
            // The seeded layout puts a bound Heading block above this block so
            // the words are editable, and turns the built-in title off.
            'showTitle'       => (bool) ($attrs['showTitle'] ?? true),
            'raised'          => Money::format((int) $campaign->raised_cents, $campaign->currency),
            'goalLabel'       => $goalCents > 0
                /* translators: %s: formatted goal amount */
                ? sprintf(__('raised of %s goal', 'dono'), Money::format($goalCents, $campaign->currency))
                : __('raised so far', 'dono'),
            'headingLevel'    => max(1, min(3, (int) ($attrs['headingLevel'] ?? 1))),
            'align'           => in_array($attrs['align'] ?? 'left', ['left', 'center'], true)
                ? (string) $attrs['align'] : 'left',
            'styleVars'       => $this->styleVars($campaign),
        ]);
    }
}
