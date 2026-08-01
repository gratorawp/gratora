<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

use Dono\Campaigns\Campaign;
use WP_Post;

/**
 * Put a campaign's style on the page once, not on each block.
 *
 * Emitting the tokens per block wrapper meant only our own blocks were styled.
 * Anything an organiser added from the editor, a heading, a paragraph, a
 * button, sat outside every wrapper and inherited nothing, so a campaign's
 * style stopped at the blocks we happened to ship. Campaign pages are ordinary
 * pages an organiser edits, so the tokens belong to the page.
 *
 * This began in the P2P add-on, where the same reasoning applies to fundraiser
 * and team pages. Nothing about it was ever P2P specific: it resolves a
 * campaign from any post, so a standard campaign page needs exactly this. The
 * body class was even reaching standard campaign pages already, because P2P
 * added it for any campaign page it could resolve, while the rule defining the
 * tokens was attached to the P2P stylesheet and so never printed there. The
 * class promised a rule that did not exist.
 *
 * @version 1.0.0
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
     * organiser's own headings and paragraphs still belongs to its campaign.
     */
    public const HANDLE = 'dono-campaign-page';

    private ?Campaign $campaign = null;

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
     * fall back to the campaign it carries: an organiser previewing one should
     * see their own colours, not the defaults.
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
     * campaign's own colours rather than the design defaults. Scoped to the
     * canvas wrapper, which is where the editor puts the content, so nothing
     * leaks into the surrounding admin.
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
