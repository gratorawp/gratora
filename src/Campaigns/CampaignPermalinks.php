<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Foundation\Hooks\HookProvider;
use WP_Post;

/**
 * Serves campaign pages under /campaigns/<slug>/. Campaigns are ordinary top-level
 * pages; a rewrite maps the prefixed URL and a page_link filter keeps every
 * generated permalink carrying the prefix.
 */
final class CampaignPermalinks extends HookProvider
{
    public const PREFIX = 'campaigns';

    protected function actions(): array
    {
        return ['init' => 'addRule'];
    }

    protected function filters(): array
    {
        return [
            'page_link'          => ['filterLink', 10, 2],
            'redirect_canonical' => ['guardCanonical', 10, 1],
        ];
    }

    public function addRule(): void
    {
        if (! get_option('permalink_structure')) {
            return;
        }
        add_rewrite_rule(
            '^' . self::PREFIX . '/([^/]+)/?$',
            'index.php?pagename=$matches[1]',
            'top'
        );
    }

    public function filterLink(string $link, int $postId): string
    {
        if (! get_option('permalink_structure') || ! $this->isCampaignPage($postId)) {
            return $link;
        }
        $page = get_post($postId);
        if (! $page instanceof WP_Post) {
            return $link;
        }
        return home_url(user_trailingslashit(self::PREFIX . '/' . $page->post_name));
    }

    /** @param string|false $redirect */
    public function guardCanonical($redirect)
    {
        if (is_page() && $this->isCampaignPage(get_queried_object_id())) {
            return false;
        }
        return $redirect;
    }

    private function isCampaignPage(int $postId): bool
    {
        return $postId > 0
            && get_post_type($postId) === 'page'
            && (int) get_post_meta($postId, '_dono_campaign_id', true) > 0;
    }
}
