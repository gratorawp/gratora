<?php

declare(strict_types=1);

namespace Gratora\Campaigns;

use Gratora\Foundation\Hooks\HookProvider;
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
 *
 * A classic theme resolves page templates to PHP files, so it is handed one
 * that does the same job.
 *
 * @since 1.0.0
 */
final class CampaignPageTemplate extends HookProvider
{
    public const SLUG = 'gratora-campaign-page';

    /**
     * The page's own measure, matching --dp-measure in page.css.
     *
     * The two have to agree: the stylesheet caps every band here, and a layout
     * set narrower would crop them while a wider one would let them out.
     */
    public const MEASURE = '1200px';

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return ['init' => 'registerTemplate'];
    }

    /** @since 1.0.0 */
    protected function filters(): array
    {
        // The slug means nothing to a classic theme, which gets the file
        // instead: ahead of the chrome filter (9) and the add-on routes (10),
        // so each of them can still take the page. Same gate as the P2P routes.
        return wp_is_block_theme()
            ? ['page_template_hierarchy' => 'forceTemplate']
            : ['template_include' => ['classicTemplate', 8, 1]];
    }

    /** @since 1.0.0 */
    public function registerTemplate(): void
    {
        if (! function_exists('register_block_template')) {
            return;
        }
        register_block_template('gratora//' . self::SLUG, [
            'title'       => __('Campaign page', 'gratora-donation-platform'),
            'description' => __('Site header and footer around the campaign page content, without the theme page banner.', 'gratora-donation-platform'),
            'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
                . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained","contentSize":"' . self::MEASURE . '","wideSize":"' . self::MEASURE . '"}} -->'
                . '<main class="wp-block-group"><!-- wp:post-content /--></main>'
                . '<!-- /wp:group -->'
                . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
        ]);
    }

    /**
     * @param string[] $templates
     * @return string[]
     *
     * @since 1.0.0
     */
    public function forceTemplate(array $templates): array
    {
        if ($this->isACampaignPageLeftToUs()) {
            array_unshift($templates, self::SLUG);
        }
        return $templates;
    }

    /** @unreleased */
    public function classicTemplate(string $template): string
    {
        return $this->isACampaignPageLeftToUs() ? __DIR__ . '/views/classic-chrome.php' : $template;
    }

    /** A page template its owner picked for it is the owner opting out. */
    private function isACampaignPageLeftToUs(): bool
    {
        $post = get_post(get_queried_object_id());
        if (! $post instanceof WP_Post || $post->post_type !== 'page') {
            return false;
        }
        if ((int) get_post_meta($post->ID, '_gratora_campaign_id', true) <= 0) {
            return false;
        }
        $explicit = (string) get_post_meta($post->ID, '_wp_page_template', true);

        return $explicit === '' || $explicit === 'default';
    }
}
