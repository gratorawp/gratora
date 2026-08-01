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
     *
     * Blocks used to pass the accent alone, which left every other token in the
     * stylesheet resolving to its Sass fallback. See CampaignStyleVars.
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
     * @return array<string,mixed>
     */
    public function supports(): array
    {
        return ['visibility' => true];
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

    protected function notBoundNotice(): string
    {
        if (! is_user_logged_in() || ! current_user_can('edit_posts')) {
            return '';
        }
        return '<div class="dono-block-notice">'
            . esc_html__('This block is not bound to a campaign. Pick one in the block sidebar.', 'dono')
            . '</div>';
    }
}
