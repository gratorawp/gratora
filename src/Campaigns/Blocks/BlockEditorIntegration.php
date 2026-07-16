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
                DONO_VERSION
            );
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

        $cssPath = DONO_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                DONO_URL . 'build/admin/campaign-blocks.css',
                [],
                DONO_VERSION
            );
        }

        // Modal JS only needed when the donate-button block is present.
        if ($hasDonateButton) {
            wp_enqueue_script(
                'dono-donate-button-modal',
                DONO_URL . 'assets/donate-button/modal.js',
                [],
                DONO_VERSION,
                true
            );
        }
    }
}
