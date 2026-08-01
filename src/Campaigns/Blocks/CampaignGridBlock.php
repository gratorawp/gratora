<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;

/**
 * Responsive card grid of other published campaigns ("more ways to give" section
 * or a standalone browse page).
 */
final class CampaignGridBlock extends CampaignBlock
{
    public function name(): string
    {
        return 'dono/campaign-grid';
    }

    public function attributes(): array
    {
        // campaignId here means "the campaign to exclude" (the current one).
        return $this->campaignIdAttr() + [
            'count'   => ['type' => 'integer', 'default' => 3],
            'orderBy' => ['type' => 'string',  'default' => 'recent'],
            'heading' => ['type' => 'string',  'default' => ''],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $excludeId = (int) ($attrs['campaignId'] ?? 0);
        if ($excludeId === 0) {
            global $post;
            if ($post instanceof \WP_Post) {
                $excludeId = (int) get_post_meta($post->ID, '_dono_campaign_id', true);
            }
        }

        $count   = max(1, min(12, (int) ($attrs['count'] ?? 3)));
        $orderBy = in_array($attrs['orderBy'] ?? 'recent', ['recent', 'most-funded', 'ending-soon'], true)
            ? (string) $attrs['orderBy'] : 'recent';

        $campaigns = $this->campaigns->otherPublished($excludeId, $count, $orderBy);
        if (empty($campaigns)) {
            return (is_user_logged_in() && current_user_can('edit_posts'))
                ? '<div class="dono-block-notice">'
                    . esc_html__('No other published campaigns to show yet. This block will list them once you have more.', 'dono')
                    . '</div>'
                : '';
        }

        $cards = [];
        foreach ($campaigns as $c) {
            $goalCents = $c->goal_type === 'amount' ? (int) $c->goal_cents : 0;
            $percent   = $goalCents > 0
                ? min(100, (int) round((int) $c->raised_cents / $goalCents * 100))
                : 0;
            $cards[] = [
                'title'     => (string) $c->title,
                'blurb'     => (string) ($c->description ?? ''),
                'imageUrl'  => $c->image_attachment_id
                    ? wp_get_attachment_image_url((int) $c->image_attachment_id, 'medium_large')
                    : null,
                'url'       => $c->page_id ? get_permalink((int) $c->page_id) : '',
                'raised'    => Money::compact((int) $c->raised_cents, $c->currency),
                'goalLabel' => $goalCents > 0
                    /* translators: %s: formatted goal amount */
                    ? sprintf(__('of %s', 'dono'), Money::compact($goalCents, $c->currency))
                    : '',
                'percent'   => $percent,
                'accent'    => $c->accentColor(),
            ];
        }

        $heading = trim((string) ($attrs['heading'] ?? ''));
        if ($heading === '') {
            $heading = __('More ways to give', 'dono');
        }

        return View::loadRelative(__DIR__, 'views/campaign-grid', [
            'heading' => $heading,
            'cards'   => $cards,
            // The grid's own chrome follows the campaign the page is about.
            // Each card keeps its own accent, since a card is another campaign.
            'styleVars' => $this->styleVars($this->resolveCampaign($attrs)),
        ]);
    }
}
