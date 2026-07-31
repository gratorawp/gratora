<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Hooks\HookProvider;

/**
 * Block Bindings source resolving campaign stats for core blocks (e.g. bind a
 * Heading to dono/campaign:raised). args.campaign_id is optional; it falls back
 * to the page's _dono_campaign_id meta, same as CampaignBlock.
 */
final class CampaignBindings extends HookProvider
{
    public function __construct(private CampaignRepository $campaigns)
    {
    }

    protected function actions(): array
    {
        return [
            'init' => 'registerSource',
        ];
    }

    public function registerSource(): void
    {
        if (! function_exists('register_block_bindings_source')) return;

        register_block_bindings_source('dono/campaign', [
            'label'              => __('Dono campaign', 'dono'),
            'get_value_callback' => [$this, 'resolve'],
            'uses_context'       => ['postId'],
        ]);
    }

    /**
     * @param array{key?:string,campaign_id?:int|string} $args
     * @param \WP_Block $block
     * @param string $attributeName Block attribute being resolved (content, url, ...).
     */
    public function resolve(array $args, $block, string $attributeName): ?string
    {
        $key      = (string) ($args['key'] ?? '');
        $campaign = $this->resolveCampaign($args, $block);

        if (! $campaign || $key === '') return null;

        return $this->valueFor($campaign, $key);
    }

    private function resolveCampaign(array $args, $block): ?Campaign
    {
        $explicit = isset($args['campaign_id']) ? (int) $args['campaign_id'] : 0;
        if ($explicit > 0) {
            return $this->campaigns->findRenderable($explicit);
        }

        // Fallback: the page's bound campaign via _dono_campaign_id post meta.
        $postId = 0;
        if (is_object($block) && property_exists($block, 'context') && is_array($block->context)) {
            $postId = (int) ($block->context['postId'] ?? 0);
        }
        if ($postId === 0) {
            $postId = (int) get_the_ID();
        }
        if ($postId === 0) return null;

        $bound = (int) get_post_meta($postId, '_dono_campaign_id', true);
        return $bound > 0 ? $this->campaigns->findRenderable($bound) : null;
    }

    private function valueFor(Campaign $campaign, string $key): ?string
    {
        $type    = $campaign->goal_type ?: 'amount';
        $current = match ($type) {
            'donations' => (int) $campaign->donations_count,
            'donors'    => (int) $campaign->donors_count,
            default     => (int) $campaign->raised_cents,
        };
        $target = match ($type) {
            'amount' => (int) ($campaign->goal_cents ?? 0),
            default  => (int) ($campaign->goal_count ?? 0),
        };
        $percent = $target > 0 ? min(100, (int) round(($current / $target) * 100)) : 0;

        return match ($key) {
            'title'             => (string) $campaign->title,
            'description'       => (string) ($campaign->description ?? ''),

            'raised'            => Money::format((int) $campaign->raised_cents, (string) $campaign->currency),
            'raised_cents'      => (string) (int) $campaign->raised_cents,

            'goal'              => $target > 0 && $type === 'amount'
                ? Money::format($target, (string) $campaign->currency)
                : (string) $target,
            'goal_cents'        => (string) (int) ($campaign->goal_cents ?? 0),
            'goal_count'        => (string) (int) ($campaign->goal_count ?? 0),

            'donors_count'      => (string) (int) $campaign->donors_count,
            'donations_count'   => (string) (int) $campaign->donations_count,

            'percent'           => (string) $percent,
            'percent_label'     => $percent . '%',

            'currency'          => (string) $campaign->currency,
            'ends_at'           => (string) ($campaign->ends_at ?? ''),
            'days_left'         => (string) $this->daysLeft($campaign),

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
     */
    private function imageUrl(Campaign $campaign): ?string
    {
        if (! $campaign->image_attachment_id) return null;
        $src = wp_get_attachment_image_url((int) $campaign->image_attachment_id, 'large');
        return $src ?: null;
    }

    private function imageAlt(Campaign $campaign): ?string
    {
        if (! $campaign->image_attachment_id) return null;
        $alt = get_post_meta((int) $campaign->image_attachment_id, '_wp_attachment_image_alt', true);
        return is_string($alt) && $alt !== '' ? $alt : (string) $campaign->title;
    }

    private function pageUrl(Campaign $campaign): ?string
    {
        if (! $campaign->page_id) return null;
        $url = get_permalink((int) $campaign->page_id);
        return $url ?: null;
    }

    private function daysLeft(Campaign $campaign): int
    {
        if (! $campaign->ends_at) return 0;
        $end = strtotime((string) $campaign->ends_at);
        if ($end === false) return 0;
        $diff = (int) ceil(($end - time()) / 86400);
        return max(0, $diff);
    }
}
