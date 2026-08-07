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
            'showCover'       => ['type' => 'boolean', 'default' => true],
            'showSummary'     => ['type' => 'boolean', 'default' => true],
            // Separate from showSummary, which also carries the raised and goal
            // figures. Beside a donation form the button is the only redundant
            // part: it scrolls to something already on screen.
            'showDonate'      => ['type' => 'boolean', 'default' => true],
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

        $imageId  = (int) ($campaign->image_attachment_id ?? 0);
        $imageUrl = $imageId ? wp_get_attachment_image_url($imageId, 'large') : null;
        // The attachment's own alt text, deliberately falling back to none.
        // The hero title sits directly above the photo, so alt="<title>" had a
        // screen reader announce the campaign name twice in a row.
        $imageAlt = $imageId
            ? trim((string) get_post_meta($imageId, '_wp_attachment_image_alt', true))
            : '';

        $goalType  = (string) ($campaign->goal_type ?? 'amount');
        $goalCents = $goalType === 'amount' ? (int) $campaign->goal_cents : 0;
        $goalCount = $goalType === 'amount' ? 0 : (int) ($campaign->goal_count ?? 0);
        $progress  = match ($goalType) {
            'donations' => (int) $campaign->donations_count,
            'donors'    => (int) $campaign->donors_count,
            default     => (int) $campaign->raised_cents,
        };
        $target    = $goalType === 'amount' ? $goalCents : $goalCount;

        $goalPercent = $target > 0 ? min(100, (int) round($progress / $target * 100)) : 0;

        return View::loadRelative(__DIR__, 'views/campaign-hero', [
            'title'           => $campaign->title,
            'imageUrl'        => $imageUrl,
            'imageId'         => $imageId,
            'imageAlt'        => $imageAlt,
            'showCover'       => (bool) ($attrs['showCover'] ?? true) && $imageUrl,
            // Not gated on having raised something. A campaign on day one showed
            // no money line and no goal, so the hero made no ask at the one
            // moment it has nothing else to show.
            'showSummary'     => (bool) ($attrs['showSummary'] ?? true),
            'showDonate'      => (bool) ($attrs['showDonate'] ?? true),
            // The seeded layout puts a bound Heading block above this block so
            // the words are editable, and turns the built-in title off.
            'showTitle'       => (bool) ($attrs['showTitle'] ?? true),
            // A campaign counting donations or donors leads with that count,
            // not with money. Reading "raised so far" under a donor goal, with
            // no goal in sight, was what dropping campaign-progress from the
            // seed left behind: that block understood all three goal types and
            // the hero only understood one.
            'raised'          => match ($goalType) {
                'donations' => number_format_i18n((int) $campaign->donations_count),
                'donors'    => number_format_i18n((int) $campaign->donors_count),
                default     => Money::format((int) $campaign->raised_cents, $campaign->currency),
            },
            'goalLabel'       => match (true) {
                $goalType === 'donations' && $target > 0 => sprintf(
                    /* translators: %s: the number of donations targeted. */
                    __('of %s donations', 'dono'),
                    number_format_i18n($target)
                ),
                $goalType === 'donors' && $target > 0 => sprintf(
                    /* translators: %s: the number of donors targeted. */
                    __('of %s donors', 'dono'),
                    number_format_i18n($target)
                ),
                $goalType === 'donations' => __('donations so far', 'dono'),
                $goalType === 'donors'    => __('donors so far', 'dono'),
                $target > 0 => sprintf(
                    /* translators: %s: formatted goal amount */
                    __('raised of %s goal', 'dono'),
                    Money::format($goalCents, $campaign->currency)
                ),
                default => __('raised so far', 'dono'),
            },
            'hasGoal'         => $target > 0,
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
