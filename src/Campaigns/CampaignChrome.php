<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Hides the theme header/footer on a campaign's public pages when the campaign
 * opts in. Header and footer are core/template-part blocks, so suppressing them at
 * render covers any block theme and every add-on route resolving to the campaign.
 *
 * @since 1.0.0
 */
final class CampaignChrome extends HookProvider
{
    private bool $resolved = false;
    private ?Campaign $campaign = null;

    /** @since 1.0.0 */
    public function __construct(private CampaignRepository $campaigns)
    {
    }

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            // Block themes: drop the header/footer template-part blocks at render.
            'render_block_core/template-part' => ['hideChrome', 10, 2],
            // Classic themes (no template parts): swap to a chrome template that
            // omits get_header()/get_footer(). Runs before add-on classic routes
            // (priority 10) so a P2P route can still take over and handle its own.
            'template_include'                => ['classicTemplate', 9, 1],
        ];
    }

    /**
     * On a classic theme, render a campaign page with hidden chrome through a
     * blank-canvas template (preserves wp_head()/wp_footer(); drops the theme's
     * visual header/footer and page wrapper).
     *
     * @since 1.0.0
     */
    public function classicTemplate(string $template): string
    {
        if (wp_is_block_theme()) {
            return $template;
        }
        $campaign = $this->current();
        if ($campaign === null) {
            return $template;
        }
        $GLOBALS['_dono_chrome_flags'] = [
            'header' => (bool) $campaign->hide_header,
            'footer' => (bool) $campaign->hide_footer,
        ];
        return __DIR__ . '/views/classic-chrome.php';
    }

    /**
     * Open the HTML document for a classic chrome page: the theme header when
     * kept, otherwise a minimal head/body that still runs wp_head()/wp_body_open()
     * so styles, scripts and meta load normally.
     *
     * @since 1.0.0
     */
    public static function openDocument(bool $hideHeader): void
    {
        if (! $hideHeader) {
            get_header();
            return;
        }
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
        wp_body_open();
    }

    /**
     * Close the document: the theme footer when kept, otherwise just wp_footer().
     *
     * @since 1.0.0
     */
    public static function closeDocument(bool $hideFooter): void
    {
        if (! $hideFooter) {
            get_footer();
            return;
        }
        wp_footer();
        echo "\n</body>\n</html>";
    }

    /**
     * @param array<string,mixed> $block
     *
     * @since 1.0.0
     */
    public function hideChrome(string $content, array $block): string
    {
        $campaign = $this->current();
        if ($campaign === null) {
            return $content;
        }

        $attrs = (array) ($block['attrs'] ?? []);
        $slug  = (string) ($attrs['slug'] ?? '');
        $tag   = (string) ($attrs['tagName'] ?? '');

        if ($campaign->hide_header && ($slug === 'header' || $tag === 'header')) {
            return '';
        }
        if ($campaign->hide_footer && ($slug === 'footer' || $tag === 'footer')) {
            return '';
        }
        return $content;
    }

    /**
     * The campaign behind the current front-end page, resolved once per request.
     * Returns null when there's nothing to hide, so the per-block path is cheap.
     *
     * @since 1.0.0
     */
    private function current(): ?Campaign
    {
        if ($this->resolved) {
            return $this->campaign;
        }
        $this->resolved = true;

        if (is_admin()) {
            return null;
        }
        $postId = get_queried_object_id();
        if ($postId <= 0) {
            return null;
        }
        $campaignId = (int) get_post_meta($postId, '_dono_campaign_id', true);
        if ($campaignId <= 0) {
            return null;
        }
        $campaign = $this->campaigns->findRenderable($campaignId);
        if ($campaign === null || (! $campaign->hide_header && ! $campaign->hide_footer)) {
            return null;
        }
        $this->campaign = $campaign;
        return $campaign;
    }
}
