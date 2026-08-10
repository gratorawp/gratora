<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

use Dono\Campaigns\Campaign;
use WP_Post;

/**
 * Put a campaign's style on the page once, not on each block.
 *
 * Campaign pages are ordinary pages an organizer edits. A heading, a paragraph
 * or a button added from the editor sits outside every block wrapper and
 * inherits nothing from it, so per-wrapper tokens would style only the blocks
 * we ship. The tokens belong to the page.
 *
 * Resolves a campaign from any post, so add-on routes (fundraiser and team
 * pages) are covered by the same path.
 *
 * @since 1.0.0
 */
final class PageStyle
{
    private const BODY_CLASS = 'dono-campaign-styled';

    /**
     * The campaign page foundation, and the campaign's own tokens inlined onto
     * it. One handle for both so the tokens cannot arrive without the rules
     * that read them, and so an add-on can depend on the foundation by name.
     *
     * Deliberately not the block stylesheet's handle: that one is enqueued only
     * when a campaign block is on the page, and a page holding nothing but an
     * organizer's own headings and paragraphs still belongs to its campaign.
     */
    public const HANDLE = 'dono-campaign-page';

    private ?Campaign $campaign = null;

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('wp', [$this, 'resolve']);
        // Registered early and unconditionally: an add-on naming it as a
        // dependency must be able to resolve it even where we do not enqueue.
        add_action('wp_enqueue_scripts', [$this, 'registerStyle'], 1);
        add_action('wp_enqueue_scripts', [$this, 'emit'], 20);
        add_filter('body_class', [$this, 'bodyClass']);
        // enqueue_block_assets is the hook that reaches the iframed editor
        // canvas; enqueue_block_editor_assets does not.
        add_action('enqueue_block_assets', [$this, 'registerStyle'], 1);
        add_action('enqueue_block_assets', [$this, 'emitForEditor'], 20);
    }

    /** @since 1.0.0 */
    public function registerStyle(): void
    {
        if (wp_style_is(self::HANDLE, 'registered')) {
            return;
        }
        $path = DONO_DIR . 'assets/campaign-page/page.css';
        wp_register_style(
            self::HANDLE,
            DONO_URL . 'assets/campaign-page/page.css',
            [],
            // mtime, not DONO_VERSION: the file changes without a release and a
            // stale cache means invisible restyles.
            (string) (@filemtime($path) ?: DONO_VERSION)
        );
    }

    /**
     * The campaign whose page is being viewed. Resolved on `wp`, before the
     * header runs, so body_class and the enqueue both see it.
     *
     * @since 1.0.0
     */
    public function resolve(): void
    {
        if (is_admin()) {
            return;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0) {
            return;
        }

        $this->campaign = self::campaignForPost($postId);
    }

    /**
     * A campaign page is the campaign's own page_id. Add-on routes (the
     * fundraiser, team and start pages) resolve to that same page, so page_id
     * covers them too. A layout page is a child and is nobody's page_id, so
     * fall back to the campaign it carries: an organizer previewing one should
     * see their own colors, not the defaults.
     *
     * @since 1.0.0
     */
    public static function campaignForPost(int $postId): ?Campaign
    {
        $campaign = Campaign::query()->find('page_id', $postId);
        if ($campaign !== null) {
            return $campaign;
        }

        $campaignId = (int) get_post_meta($postId, '_dono_campaign_id', true);

        return $campaignId > 0 ? Campaign::query()->find('id', $campaignId) : null;
    }

    /**
     * @param  array<int,string> $classes
     * @return array<int,string>
     *
     * @since 1.0.0
     */
    public function bodyClass(array $classes): array
    {
        if ($this->campaign !== null) {
            $classes[] = self::BODY_CLASS;
        }
        return $classes;
    }

    /**
     * Scoped to the body class rather than :root so a campaign page cannot
     * restyle the admin bar or anything else outside it.
     *
     * @since 1.0.0
     */
    public function emit(): void
    {
        if ($this->campaign === null) {
            return;
        }

        $vars = CampaignStyleVars::forCampaign($this->campaign);
        if ($vars === '') {
            return;
        }

        $this->registerStyle();
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, '.' . self::BODY_CLASS . '{' . $vars . '}');
    }

    /**
     * The same tokens inside the editor canvas, so a page is composed in the
     * campaign's own colors rather than the design defaults. Scoped to the
     * canvas wrapper, which is where the editor puts the content, so nothing
     * leaks into the surrounding admin.
     *
     * @since 1.0.0
     */
    public function emitForEditor(): void
    {
        if (! is_admin()) {
            return;
        }

        $post = get_post();
        if (! $post instanceof WP_Post) {
            return;
        }

        $campaign = self::campaignForPost($post->ID);
        if ($campaign === null) {
            return;
        }

        $vars = CampaignStyleVars::forCampaign($campaign);
        if ($vars === '') {
            return;
        }

        $this->registerStyle();
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, '.editor-styles-wrapper{' . $vars . '}');
    }
}
