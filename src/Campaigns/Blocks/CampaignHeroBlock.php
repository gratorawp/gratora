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
            'showProgress'    => ['type' => 'boolean', 'default' => true],
            'showStats'       => ['type' => 'boolean', 'default' => true],
            'donateLabel'     => ['type' => 'string',  'default' => ''],
            'headingLevel'    => ['type' => 'integer', 'default' => 1],
            // Registered so saved pages stay valid. The readout is always left
            // aligned now, and the description moved to the About section as a
            // bound paragraph, so neither reaches the view.
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

        $goalPercent = $goalCents > 0
            ? min(100, (int) round((int) $campaign->raised_cents / $goalCents * 100))
            : 0;

        return View::loadRelative(__DIR__, 'views/campaign-hero', [
            'title'           => $campaign->title,
            'imageUrl'        => $imageUrl,
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
            'hasGoal'         => $goalCents > 0,
            'percent'         => $goalPercent,
            'showProgress'    => (bool) ($attrs['showProgress'] ?? true),
            'showStats'       => (bool) ($attrs['showStats'] ?? true),
            'donorsCount'     => (int) $campaign->donors_count,
            'donationsCount'  => (int) $campaign->donations_count,
            'daysLeft'        => $this->daysLeft($campaign),
            'donateLabel'     => (string) ($attrs['donateLabel'] ?? '') ?: __('Donate', 'dono'),
            'donateUrl'       => '#dono-form',
            'headingLevel'    => max(1, min(3, (int) ($attrs['headingLevel'] ?? 1))),
            'styleVars'       => $this->styleVars($campaign),
        ]);
    }

    /** Whole days until the campaign closes, or null when it does not. */
    private function daysLeft(\Dono\Campaigns\Campaign $campaign): ?int
    {
        $endsAt = (string) ($campaign->ends_at ?? '');
        if ($endsAt === '') {
            return null;
        }

        $end = strtotime($endsAt);
        if ($end === false) {
            return null;
        }

        $days = (int) ceil(($end - time()) / DAY_IN_SECONDS);

        return $days > 0 ? $days : 0;
    }
}
