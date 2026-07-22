<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Foundation\Hooks\HookProvider;
use WP_Post;

/**
 * Routes campaign pages through a minimal block template - site chrome around
 * the page's own content - instead of the theme's page template. Theme page
 * templates typically add a decorative title banner above the content, which
 * duplicates the campaign hero and makes the published page diverge from what
 * the admin composed in the editor.
 *
 * Registered before the add-on route filters on the same hook, so a route
 * template (e.g. a fundraiser page) unshifts later and keeps precedence.
 */
final class CampaignPageTemplate extends HookProvider
{
    public const SLUG = 'dono-campaign-page';

    protected function actions(): array
    {
        return ['init' => 'registerTemplate'];
    }

    protected function filters(): array
    {
        // Block themes only; a classic theme resolves page templates to PHP
        // files, where this slug means nothing. Same gate as the P2P routes.
        return wp_is_block_theme() ? ['page_template_hierarchy' => 'forceTemplate'] : [];
    }

    public function registerTemplate(): void
    {
        if (! function_exists('register_block_template')) {
            return;
        }
        register_block_template('dono//' . self::SLUG, [
            'title'       => __('Campaign page', 'dono'),
            'description' => __('Site header and footer around the campaign page content, without the theme page banner.', 'dono'),
            'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
                . '<!-- wp:group {"tagName":"main","layout":{"type":"default"}} -->'
                . '<main class="wp-block-group"><!-- wp:post-content /--></main>'
                . '<!-- /wp:group -->'
                . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
        ]);
    }

    /**
     * @param string[] $templates
     * @return string[]
     */
    public function forceTemplate(array $templates): array
    {
        $post = get_post(get_queried_object_id());
        if (! $post instanceof WP_Post || $post->post_type !== 'page') {
            return $templates;
        }
        if ((int) get_post_meta($post->ID, '_dono_campaign_id', true) <= 0) {
            return $templates;
        }
        // An explicitly assigned page template is the admin opting out.
        $explicit = (string) get_post_meta($post->ID, '_wp_page_template', true);
        if ($explicit !== '' && $explicit !== 'default') {
            return $templates;
        }
        array_unshift($templates, self::SLUG);
        return $templates;
    }
}
