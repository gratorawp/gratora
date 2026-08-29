<?php

declare(strict_types=1);

namespace GiveFlow\Campaigns\Blocks;

use GiveFlow\Campaigns\CampaignPageTemplate;
use GiveFlow\Campaigns\CampaignRepository;
use WP_Theme_JSON_Data;

/**
 * Registers the campaign block category, editor assets and front-end enqueues.
 *
 * @since 1.0.0
 */
final class BlockEditorIntegration
{
    private const HANDLE_EDITOR    = 'giveflow-campaign-blocks-editor';
    private const HANDLE_EDITOR_UI = 'giveflow-campaign-blocks-editor-ui';
    private const HANDLE_FRONTEND = 'giveflow-campaign-blocks';
    private const BUILD_DIR       = 'build/admin/campaign-blocks';

    // Must list every registered campaign block: gates the front-end CSS enqueue.
    private const BLOCK_NAMES = [
        'giveflow/campaign-image',
        'giveflow/campaign-stat',
        'giveflow/campaign-progress',
        'giveflow/campaign-grid',
        'giveflow/donate-button',
        'giveflow/donation-form',
        'giveflow/top-donors',
        'giveflow/recent-donations',
        'giveflow/supporter-wall',
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
        add_filter('wp_theme_json_data_theme',    [$this, 'matchEditorMeasureToTemplate']);
    }

    /**
     * Exposed through REST so editor-side block UIs can hide their campaign
     * picker on a post already tied to a campaign.
     *
     * @since 1.0.0
     */
    public function registerPageMeta(): void
    {
        register_post_meta('page', '_giveflow_campaign_id', [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => static fn(): bool => current_user_can('edit_posts'),
        ]);
    }

    /**
     * Is the editor open on a page that belongs to a campaign?
     *
     * The same question the switcher asks itself in JS, asked here so its
     * stylesheet is not sent to every other post and page in the site.
     *
     * @since 1.0.0
     */
    private static function editingCampaignPage(): bool
    {
        return self::editedCampaignId() > 0;
    }

    /** The campaign the open editor belongs to, or 0. @since 1.0.0 */
    private static function editedCampaignId(): int
    {
        $postId = get_the_ID();

        if (! $postId && isset($_GET['post'])) {
            $postId = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which post the editor is on, not acting on it.
        }

        return $postId > 0 ? (int) get_post_meta((int) $postId, '_giveflow_campaign_id', true) : 0;
    }

    /**
     * Whether this campaign's page can be reshaped by a layout template.
     *
     * A campaign type that lays its own page out owns every block on it, so
     * offering to replace them all is offering to delete the thing the type
     * exists for. Peer to peer seeds its own layout this way.
     *
     * @since 1.0.0
     */
    public static function pageTemplatesAvailable(): bool
    {
        $campaignId = self::editedCampaignId();
        if ($campaignId <= 0) {
            return false;
        }

        $campaign = (new CampaignRepository())->findById($campaignId);
        if ($campaign === null) {
            return false;
        }

        return (bool) apply_filters(
            'giveflow.campaign.supports_page_templates',
            true,
            (string) $campaign->campaign_type,
            $campaign
        );
    }

    /**
     * The post editor lays campaign content out in the theme's root layout, not
     * in the campaign template, so its canvas measures by the theme while the
     * front end measures by us (Twenty Twenty-Five: 645px against 1200px).
     * Handing the editor our measure is what makes the two agree.
     *
     * Scoped to the request editing a campaign page, so every other post, the
     * site editor and the front end keep the theme's own measure.
     *
     * @since 1.0.0
     */
    public function matchEditorMeasureToTemplate(WP_Theme_JSON_Data $data): WP_Theme_JSON_Data
    {
        if (! is_admin() || ! self::editingCampaignPage()) {
            return $data;
        }

        return $data->update_with([
            'version'  => 2,
            'settings' => [
                'layout' => [
                    'contentSize' => CampaignPageTemplate::MEASURE,
                    'wideSize'    => CampaignPageTemplate::MEASURE,
                ],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function registerCategory(array $categories): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === 'giveflow') return $categories;
        }
        array_unshift($categories, [
            'slug'  => 'giveflow',
            'title' => __('GiveFlow', 'giveflow-fundraising-campaigns'),
            'icon'  => 'heart',
        ]);
        return $categories;
    }

    /** @since 1.0.0 */
    public function enqueueEditorAssets(): void
    {
        $assetPath = GIVEFLOW_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE_EDITOR,
            GIVEFLOW_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE_EDITOR, 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');

        // Editor-chrome styles (the layout picker's modal). Kept out of
        // campaign-blocks.css, which the front end also loads, and only sent to
        // the screens that can open the picker: the blocks themselves can be
        // used on any page, but the layout switcher shows on a campaign's own.
        $uiCss = 'build/admin/campaign-blocks-ui.css';
        if (self::pageTemplatesAvailable() && file_exists(GIVEFLOW_DIR . $uiCss)) {
            wp_enqueue_style(
                self::HANDLE_EDITOR_UI,
                GIVEFLOW_URL . $uiCss,
                ['wp-components'],
                (string) filemtime(GIVEFLOW_DIR . $uiCss)
            );
        }

        // The binding picker's field list is handed over rather than repeated in
        // JS, so the labels are translated once and the two halves cannot
        // disagree about which values exist.
        wp_add_inline_script(
            self::HANDLE_EDITOR,
            'window.giveflowCampaignBlocks = Object.assign( window.giveflowCampaignBlocks || {}, '
            . wp_json_encode([
                'bindingFields' => CampaignBindings::fields(),
                'pageTemplates' => self::pageTemplatesAvailable(),
            ]) . ' );',
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
        $cssPath = GIVEFLOW_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                GIVEFLOW_URL . 'build/admin/campaign-blocks.css',
                [],
                // mtime, not GIVEFLOW_VERSION: the built css changes without a
                // plugin release and a stale cache means invisible restyles.
                (string) (@filemtime($cssPath) ?: GIVEFLOW_VERSION)
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
                if ($name === 'giveflow/donate-button') {
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
     * has_block() only sees the post's own content, so a GiveFlow block nested in a
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
        if ($name === 'giveflow/donate-button') {
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
        $cssPath = GIVEFLOW_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                GIVEFLOW_URL . 'build/admin/campaign-blocks.css',
                [],
                (string) (@filemtime($cssPath) ?: GIVEFLOW_VERSION)
            );
            wp_style_add_data(self::HANDLE_FRONTEND, 'rtl', 'replace');
        }
    }

    /** @since 1.0.0 */
    private function enqueueDonateButtonModal(): void
    {
        if (wp_script_is('giveflow-donate-button-modal', 'enqueued')) {
            return;
        }
        wp_enqueue_script(
            'giveflow-donate-button-modal',
            GIVEFLOW_URL . 'assets/donate-button/modal.js',
            [],
            GIVEFLOW_VERSION,
            true
        );
    }
}
