<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Blocks;

use FundKit\Campaigns\Campaign;
use FundKit\Campaigns\CampaignRepository;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\Hooks\HookProvider;

/**
 * Block Bindings source resolving campaign stats for core blocks (e.g. bind a
 * Heading to fundkit/campaign:raised). args.campaign_id is optional; it falls back
 * to the page's _fundkit_campaign_id meta, same as CampaignBlock.
 *
 * @since 1.0.0
 */
final class CampaignBindings extends HookProvider
{
    /** @since 1.0.0 */
    public function __construct(private CampaignRepository $campaigns)
    {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'init' => 'registerSource',
        ];
    }

    /**
     * The values a campaign can state, as the editor's field picker lists them.
     * Machine forms (raised_cents, percent, currency) still resolve but are left
     * off: they are for a layout that does arithmetic, not for somebody choosing
     * what a heading should say.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function fields(): array
    {
        return [
            'title'           => __('Title', 'fundraising-toolkit'),
            'description'     => __('Short description', 'fundraising-toolkit'),
            'image'           => __('Cover image', 'fundraising-toolkit'),
            'image_alt'       => __('Cover image description', 'fundraising-toolkit'),
            'url'             => __('Page link', 'fundraising-toolkit'),
            'raised'          => __('Raised', 'fundraising-toolkit'),
            'goal'            => __('Goal', 'fundraising-toolkit'),
            'percent_label'   => __('Percent of goal', 'fundraising-toolkit'),
            'donors_count'    => __('Donors', 'fundraising-toolkit'),
            'donations_count' => __('Donations', 'fundraising-toolkit'),
            'days_left'       => __('Days left', 'fundraising-toolkit'),
        ];
    }

    /**
     * Every listed value for one campaign, for the editor to preview with. Same
     * code path as the front end, so the editor cannot drift from it.
     *
     * @return array<string,?string>
     *
     * @since 1.0.0
     */
    public function valuesFor(Campaign $campaign): array
    {
        $out = [];
        foreach (array_keys(self::fields()) as $key) {
            $out[$key] = $this->valueFor($campaign, $key);
        }

        return $out;
    }

    /** @since 1.0.0 */
    public function registerSource(): void
    {
        if (! function_exists('register_block_bindings_source')) return;

        register_block_bindings_source('fundkit/campaign', [
            'label'              => __('Fundraising Toolkit campaign', 'fundraising-toolkit'),
            'get_value_callback' => [$this, 'resolve'],
            'uses_context'       => ['postId'],
        ]);
    }

    /**
     * @param array{key?:string,campaign_id?:int|string} $args
     * @param \WP_Block $block
     * @param string $attributeName Block attribute being resolved (content, url, ...).
     *
     * @since 1.0.0
     */
    public function resolve(array $args, $block, string $attributeName): ?string
    {
        $key      = (string) ($args['key'] ?? '');
        $campaign = $this->resolveCampaign($args, $block);

        if (! $campaign || $key === '') return null;

        return $this->valueFor($campaign, $key);
    }

    /** @since 1.0.0 */
    private function resolveCampaign(array $args, $block): ?Campaign
    {
        $explicit = isset($args['campaign_id']) ? (int) $args['campaign_id'] : 0;
        if ($explicit > 0) {
            return $this->campaigns->findRenderable($explicit);
        }

        $postId = 0;
        if (is_object($block) && property_exists($block, 'context') && is_array($block->context)) {
            $postId = (int) ($block->context['postId'] ?? 0);
        }
        if ($postId === 0) {
            $postId = (int) get_the_ID();
        }
        if ($postId === 0) return null;

        $bound = (int) get_post_meta($postId, '_fundkit_campaign_id', true);
        return $bound > 0 ? $this->campaigns->findRenderable($bound) : null;
    }

    /** @since 1.0.0 */
    private function valueFor(Campaign $campaign, string $key): ?string
    {
        // Hydration already casts non-nullable columns.
        $type    = $campaign->goal_type ?: 'amount';
        $current = match ($type) {
            'donations' => $campaign->donations_count,
            'donors'    => $campaign->donors_count,
            default     => $campaign->raised_cents,
        };
        $target = match ($type) {
            'amount' => (int) ($campaign->goal_cents ?? 0),
            default  => (int) ($campaign->goal_count ?? 0),
        };
        $percent = $target > 0 ? min(100, (int) round(($current / $target) * 100)) : 0;

        return match ($key) {
            'title'             => $campaign->title,
            'description'       => $campaign->description ?? '',

            'raised'            => Money::format($campaign->raised_cents, $campaign->currency),
            'raised_cents'      => (string) $campaign->raised_cents,

            'goal'              => $target > 0 && $type === 'amount'
                ? Money::format($target, $campaign->currency)
                : (string) $target,
            'goal_cents'        => (string) (int) ($campaign->goal_cents ?? 0),
            'goal_count'        => (string) (int) ($campaign->goal_count ?? 0),

            'donors_count'      => (string) $campaign->donors_count,
            'donations_count'   => (string) $campaign->donations_count,

            'percent'           => (string) $percent,
            'percent_label'     => $percent . '%',

            'currency'          => $campaign->currency,
            'ends_at'           => $campaign->ends_at ?? '',
            // Use an empty value for no end date; zero means no days left.
            'days_left'         => (string) ($this->daysLeft($campaign) ?? ''),

            'image'             => $this->imageUrl($campaign),
            'image_alt'         => $this->imageAlt($campaign),
            'url'               => $this->pageUrl($campaign),

            default             => null,
        };
    }

    /**
     * Null rather than '' when the campaign has no cover: a binding that returns
     * null leaves the block's own attribute alone, so a pattern's placeholder
     * image survives instead of rendering a broken src.
     *
     * @since 1.0.0
     */
    private function imageUrl(Campaign $campaign): ?string
    {
        if (! $campaign->image_attachment_id) return null;
        $src = wp_get_attachment_image_url($campaign->image_attachment_id, 'large');
        return $src ?: null;
    }

    /** @since 1.0.0 */
    private function imageAlt(Campaign $campaign): ?string
    {
        if (! $campaign->image_attachment_id) return null;
        $alt = get_post_meta($campaign->image_attachment_id, '_wp_attachment_image_alt', true);
        return is_string($alt) && $alt !== '' ? $alt : $campaign->title;
    }

    /** @since 1.0.0 */
    private function pageUrl(Campaign $campaign): ?string
    {
        if (! $campaign->page_id) return null;
        $url = get_permalink($campaign->page_id);
        return $url ?: null;
    }

    /** @since 1.0.0 */
    private function daysLeft(Campaign $campaign): ?int
    {
        if (! $campaign->ends_at) return null;
        $end = strtotime($campaign->ends_at);
        if ($end === false) return null;
        $diff = (int) ceil(($end - time()) / 86400);
        return max(0, $diff);
    }
}
