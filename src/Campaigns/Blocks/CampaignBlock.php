<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
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
