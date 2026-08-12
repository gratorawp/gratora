<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

/**
 * Registers the campaign block category, editor assets and front-end enqueues.
 *
 * @since 1.0.0
 */
final class BlockEditorIntegration
{
    private const HANDLE_EDITOR   = 'dono-campaign-blocks-editor';
    private const HANDLE_FRONTEND = 'dono-campaign-blocks';
    private const BUILD_DIR       = 'build/admin/campaign-blocks';

    // Must list every registered campaign block: gates the front-end CSS enqueue.
    private const BLOCK_NAMES = [
        'dono/campaign-image',
        'dono/campaign-stat',
        'dono/campaign-progress',
        'dono/campaign-grid',
        'dono/donate-button',
        'dono/donation-form',
        'dono/top-donors',
        'dono/recent-donations',
        'dono/supporter-wall',
    ];

    /** @since 1.0.0 */
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
     * Exposed through REST so editor-side block UIs can hide their campaign
     * picker on a post already tied to a campaign.
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
    public function registerCategory(array $categories): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === 'dono') return $categories;
        }
        array_unshift($categories, [
            'slug'  => 'dono',
            'title' => __('Dono', 'dono-fundraising-platform'),
            'icon'  => 'heart',
        ]);
        return $categories;
    }

    /** @since 1.0.0 */
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
        wp_set_script_translations(self::HANDLE_EDITOR, 'dono-fundraising-platform', DONO_DIR . 'languages');

        // The binding picker's field list is handed over rather than repeated in
        // JS, so the labels are translated once and the two halves cannot
        // disagree about which values exist.
        wp_add_inline_script(
            self::HANDLE_EDITOR,
            'window.donoCampaignBlocks = Object.assign( window.donoCampaignBlocks || {}, '
            . wp_json_encode(['bindingFields' => CampaignBindings::fields()]) . ' );',
            'before'
        );
    }

    /**
     * enqueue_block_assets is the only hook that reaches the iframed editor
     * canvas, so ServerSideRender previews are styled like the front end.
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
    public function enqueueFrontendAssets(): void
    {
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

        if ($hasDonateButton) {
            $this->enqueueDonateButtonModal();
        }
    }

    /**
     * has_block() only sees the post's own content, so a Dono block nested in a
     * synced pattern or template part would render unstyled. render_block fires
     * wherever the block lives, and a late enqueue still prints.
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
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
