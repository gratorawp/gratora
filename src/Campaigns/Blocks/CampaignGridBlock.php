<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Foundation\Helpers\GoalProgress;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
final class CampaignGridBlock extends CampaignBlock
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/campaign-grid';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        // campaignId excludes the current campaign.
        return $this->campaignIdAttr() + [
            'count'   => ['type' => 'integer', 'default' => 3],
            'orderBy' => ['type' => 'string',  'default' => 'recent'],
            'heading' => ['type' => 'string',  'default' => ''],
            'emptyText' => ['type' => 'string', 'default' => ''],
        ];
    }

    private static function cardValue(string $type, int $current, string $currency): string
    {
        return match ($type) {
            'donations' => sprintf(
                /* translators: %s: number of donations */
                _n('%s donation', '%s donations', $current, 'gratora-donation-platform'),
                number_format_i18n($current)
            ),
            'donors' => sprintf(
                /* translators: %s: number of donors */
                _n('%s donor', '%s donors', $current, 'gratora-donation-platform'),
                number_format_i18n($current)
            ),
            default => Money::compact($current, $currency),
        };
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $excludeId = (int) ($attrs['campaignId'] ?? 0);
        if ($excludeId === 0) {
            global $post;
            if ($post instanceof \WP_Post) {
                $excludeId = (int) get_post_meta($post->ID, '_gratora_campaign_id', true);
            }
        }

        $count   = max(1, min(12, (int) ($attrs['count'] ?? 3)));
        $orderBy = (string) ($attrs['orderBy'] ?? 'recent');
        $orderBy = in_array($orderBy, ['recent', 'most-funded', 'ending-soon'], true) ? $orderBy : 'recent';

        $campaigns = $this->campaigns->otherPublished($excludeId, $count, $orderBy);
        if (empty($campaigns)) {
            $current = $this->resolveCampaign($attrs);

            // "The only one" names a campaign on the page. A browse page has
            // none, so there is nothing for the sentence to point at.
            $only = $current !== null;

            return View::loadRelative(__DIR__, 'views/campaign-grid', [
                'heading'   => '',
                'cards'     => [],
                'emptyText' => (string) ($attrs['emptyText'] ?? '')
                    ?: ($only
                        ? __('This is the only campaign running right now.', 'gratora-donation-platform')
                        : __('No campaigns are running right now.', 'gratora-donation-platform')),
                // Unlike the donation and donor blocks, nothing a visitor does
                // makes another campaign appear. So the invitation points at
                // the one they are already reading, which is the only way to
                // give that exists today.
                'emptySubText' => $only ? __('Which makes it an easy choice.', 'gratora-donation-platform') : '',
                'emptyIcon'    => 'campaigns',
                'notice'    => (is_user_logged_in() && current_user_can('edit_posts'))
                    ? ($only
                        ? __('Only this campaign is published, so there is nothing to list. Visitors see the message above; this line is editor-only.', 'gratora-donation-platform')
                        : __('No campaigns are published, so there is nothing to list. Visitors see the message above; this line is editor-only.', 'gratora-donation-platform'))
                    : '',
                'styleVars' => $this->styleVars($current),
            ]);
        }

        $cards = [];
        foreach ($campaigns as $c) {
            // Read the way CampaignProgressBlock reads it: a campaign can
            // measure its goal in donations or donors, and reading only
            // raised_cents rendered those as nothing raised against no target.
            $type    = in_array($c->goal_type, ['amount', 'donations', 'donors'], true) ? $c->goal_type : 'amount';
            $current = match ($type) {
                'donations' => (int) $c->donations_count,
                'donors'    => (int) $c->donors_count,
                default     => (int) $c->raised_cents,
            };
            $target  = $type === 'amount' ? (int) ($c->goal_cents ?? 0) : (int) ($c->goal_count ?? 0);
            $percent = GoalProgress::percent($current, $target);

            $cards[] = [
                'title'     => (string) $c->title,
                'blurb'     => (string) ($c->description ?? ''),
                'imageUrl'  => $c->image_attachment_id
                    ? wp_get_attachment_image_url((int) $c->image_attachment_id, 'medium_large')
                    : null,
                'url'       => $c->page_id ? get_permalink((int) $c->page_id) : '',
                'raised'    => self::cardValue($type, $current, (string) $c->currency),
                'goalLabel' => $target > 0
                    /* translators: %s: the goal, as money or a count */
                    ? sprintf(__('of %s', 'gratora-donation-platform'), $type === 'amount'
                        ? Money::compact($target, $c->currency)
                        : number_format_i18n($target))
                    : '',
                'percent'   => $percent,
                'barWidth'  => GoalProgress::barWidth($percent),
                'accent'    => $c->accentColor(),
            ];
        }

        // An empty heading means no heading, not "use ours". The seeded layout
        // puts a core Heading block above this one so the words are editable,
        // so a default here would render the heading twice.
        $heading = trim((string) ($attrs['heading'] ?? ''));

        return View::loadRelative(__DIR__, 'views/campaign-grid', [
            'heading' => $heading,
            'cards'   => $cards,
            // Use page styling for the grid and each campaign’s accent for its card.
            'styleVars' => $this->styleVars($this->resolveCampaign($attrs)),
        ]);
    }
}
