<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

/**
 * Registers the Dono block category and enqueues campaign block assets.
 *
 * @version 1.0.0
 */
final class BlockEditorIntegration
{
    private const HANDLE_EDITOR   = 'dono-campaign-blocks-editor';
    private const HANDLE_FRONTEND = 'dono-campaign-blocks';
    private const BUILD_DIR       = 'build/admin/campaign-blocks';

    // Must list every registered campaign block: gates the front-end CSS enqueue.
    private const BLOCK_NAMES = [
        'dono/campaign-hero',
        'dono/campaign-stats',
        'dono/campaign-progress',
        'dono/campaign-grid',
        'dono/donate-button',
        'dono/donation-form',
        'dono/top-donors',
        'dono/recent-donations',
        'dono/supporter-wall',
    ];

    public function register(): void
    {
        add_filter('block_categories_all', [$this, 'registerCategory'], 10, 1);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
        add_action('enqueue_block_assets',        [$this, 'enqueueEditorCanvasStyle']);
        add_action('wp_enqueue_scripts',          [$this, 'enqueueFrontendAssets']);
        add_filter('render_block',                [$this, 'enqueueOnRender'], 10, 2);
        add_action('init',                        [$this, 'registerPageMeta']);
    }

    /**
     * Expose the page -> campaign back-link (_dono_campaign_id meta) through REST so
     * editor-side block UIs can hide their campaign picker on posts already tied to
     * a campaign.
     */
    public function registerPageMeta(): void
    {
        register_post_meta('page', '_dono_campaign_id', [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => static fn(): bool => current_user_can('edit_posts'),
        ]);
    }

    /** @param array<int,array<string,string>> $categories */
    public function registerCategory(array $categories): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === 'dono') return $categories;
        }
        array_unshift($categories, [
            'slug'  => 'dono',
            'title' => __('Dono', 'dono'),
            'icon'  => 'heart',
        ]);
        return $categories;
    }

    public function enqueueEditorAssets(): void
    {
        $assetPath = DONO_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE_EDITOR,
            DONO_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? DONO_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE_EDITOR, 'dono', DONO_DIR . 'languages');

        // The field list the editor's binding picker offers, named here so the
        // labels are translated once and the two halves cannot disagree about
        // which values exist.
        wp_add_inline_script(
            self::HANDLE_EDITOR,
            'window.donoCampaignBlocks = Object.assign( window.donoCampaignBlocks || {}, '
            . wp_json_encode(['bindingFields' => CampaignBindings::fields()]) . ' );',
            'before'
        );

        // Re-skin WP ToggleControl in the editor chrome (inspector panels) to
        // the Dono switch look, so block settings toggles match the rest of the
        // admin. Static CSS (chrome has no compiled-token context).
        wp_enqueue_style(
            'dono-editor-toggle',
            DONO_URL . 'assets/admin/editor-toggle.css',
            [],
            DONO_VERSION
        );
    }

    /**
     * Load the block stylesheet into the editor canvas. enqueue_block_assets is
     * the only hook that reaches the (iframed, since WP 6.3) editor canvas, so
     * the ServerSideRender previews are styled the same as the front end.
     * Front-end loading stays conditional in enqueueFrontendAssets().
     */
    public function enqueueEditorCanvasStyle(): void
    {
        if (! is_admin()) {
            return;
        }
        $cssPath = DONO_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                DONO_URL . 'build/admin/campaign-blocks.css',
                [],
                // mtime, not DONO_VERSION: the built css changes without a
                // plugin release and a stale cache means invisible restyles.
                (string) (@filemtime($cssPath) ?: DONO_VERSION)
            );
            wp_style_add_data(self::HANDLE_FRONTEND, 'rtl', 'replace');
        }
    }

    public function enqueueFrontendAssets(): void
    {
        // Only load on singular pages that contain a Dono campaign block.
        if (! is_singular()) {
            return;
        }
        $post = get_post();
        if (! $post instanceof \WP_Post) {
            return;
        }

        $hasDonateButton = false;
        $hasAnyBlock     = false;
        foreach (self::BLOCK_NAMES as $name) {
            if (has_block($name, $post)) {
                $hasAnyBlock = true;
                if ($name === 'dono/donate-button') {
                    $hasDonateButton = true;
                }
            }
        }
        if (! $hasAnyBlock) {
            return;
        }

        $this->enqueueBlockStyle();

        // Modal JS only needed when the donate-button block is present.
        if ($hasDonateButton) {
            $this->enqueueDonateButtonModal();
        }
    }

    /**
     * has_block() only sees the post's own content, so a Dono block nested in a
     * synced pattern or template part renders unstyled. render_block fires for
     * every block wherever it lives; enqueue lazily then. Late enqueues still
     * print, so the block is never left without its CSS or modal script.
     *
     * @param array<string,mixed> $block
     */
    public function enqueueOnRender(string $content, array $block): string
    {
        $name = (string) ($block['blockName'] ?? '');
        if (! in_array($name, self::BLOCK_NAMES, true)) {
            return $content;
        }
        $this->enqueueBlockStyle();
        if ($name === 'dono/donate-button') {
            $this->enqueueDonateButtonModal();
        }
        return $content;
    }

    private function enqueueBlockStyle(): void
    {
        if (wp_style_is(self::HANDLE_FRONTEND, 'enqueued')) {
            return;
        }
        $cssPath = DONO_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                DONO_URL . 'build/admin/campaign-blocks.css',
                [],
                (string) (@filemtime($cssPath) ?: DONO_VERSION)
            );
            wp_style_add_data(self::HANDLE_FRONTEND, 'rtl', 'replace');
        }
    }

    private function enqueueDonateButtonModal(): void
    {
        if (wp_script_is('dono-donate-button-modal', 'enqueued')) {
            return;
        }
        wp_enqueue_script(
            'dono-donate-button-modal',
            DONO_URL . 'assets/donate-button/modal.js',
            [],
            DONO_VERSION,
            true
        );
    }
}
