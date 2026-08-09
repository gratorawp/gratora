<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\Styling\CampaignStyleVars;
use Dono\Campaigns\Styling\PageStyle;
use Dono\Forms\Blocks\Block;

/**
 * Base for campaign-scoped page blocks. Resolves the campaign via an explicit
 * campaignId attribute or, when 0, falls back to the page's `_dono_campaign_id`
 * post meta.
 *
 * @version 1.0.0
 */
abstract class CampaignBlock implements Block
{
    public function __construct(
        protected readonly CampaignRepository $campaigns,
    ) {
    }

    /** Common attribute slot every campaign block uses. */
    protected function campaignIdAttr(): array
    {
        return ['campaignId' => ['type' => 'integer', 'default' => 0]];
    }

    /**
     * The campaign's full token map, for the block wrapper's style attribute.
     * See CampaignStyleVars.
     *
     * Empty when the page is already about this campaign, because PageStyle has
     * put the same tokens on the body, where an organiser's own headings and
     * paragraphs inherit them too. Repeating them per wrapper would add the
     * whole map nine times over and style strictly less of the page. What is
     * left is the case the body class cannot cover: a block naming a campaign
     * the page is not about, say a donate button for one campaign dropped on
     * another page, which needs its own tokens to override the page's.
     */
    protected function styleVars(?Campaign $campaign): string
    {
        if ($campaign === null) {
            return '';
        }

        $pageCampaign = PageStyle::campaignForPost((int) get_queried_object_id());
        if ($pageCampaign !== null && (int) $pageCampaign->id === (int) $campaign->id) {
            return '';
        }

        return CampaignStyleVars::forCampaign($campaign);
    }

    /**
     * Opt into the WP 7.0 responsive visibility control: these blocks render on
     * regular pages (not the form walker), so core's render_block filter can wrap
     * them with the wp-block-hidden-* classes.
     *
     * The style groups are here for the same reason: a campaign page is an
     * ordinary page, so its blocks answer to the editor's own colour, spacing
     * and type controls rather than only to the brand preset.
     *
     * @return array<string,mixed>
     */
    public function supports(): array
    {
        return [
            'visibility' => true,
            'color'      => ['background' => true, 'text' => true, 'gradients' => true],
            'spacing'    => ['margin' => true, 'padding' => true],
            'typography' => ['fontSize' => true, 'lineHeight' => true],
        ];
    }

    /** @param array<string,mixed> $attrs */
    protected function resolveCampaign(array $attrs): ?Campaign
    {
        $id = (int) ($attrs['campaignId'] ?? 0);
        if ($id === 0) {
            global $post;
            if ($post instanceof \WP_Post) {
                $id = (int) get_post_meta($post->ID, '_dono_campaign_id', true);
            }
        }
        return $id > 0 ? $this->campaigns->findRenderable($id) : null;
    }

    /**
     * Why this block rendered nothing, for someone who can do something about it.
     *
     * resolveCampaign() returns null for two reasons: nothing is bound, or the
     * bound campaign is not renderable for this viewer (draft or archived).
     * Telling an editor to pick a campaign they have already picked sends them
     * looking for a setting that is not wrong.
     */
    protected function notBoundNotice(array $attrs = []): string
    {
        if (! is_user_logged_in() || ! current_user_can('edit_posts')) {
            return '';
        }

        $id = (int) ($attrs['campaignId'] ?? 0);
        if ($id === 0) {
            global $post;
            if ($post instanceof \WP_Post) {
                $id = (int) get_post_meta($post->ID, '_dono_campaign_id', true);
            }
        }

        $bound = $id > 0 ? $this->campaigns->findById($id) : null;
        $message = $bound === null
            ? __('This block is not bound to a campaign. Pick one in the block sidebar.', 'dono')
            : sprintf(
                /* translators: %s: the campaign's status, e.g. "draft". */
                __('This campaign is %s, so this block is hidden from visitors. Publish the campaign to show it.', 'dono'),
                (string) $bound->status
            );

        return '<div class="dono-block-notice">' . esc_html($message) . '</div>';
    }
}
