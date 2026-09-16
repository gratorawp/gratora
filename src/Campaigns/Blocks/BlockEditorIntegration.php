<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignPageTemplate;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Foundation\Auth\Capabilities;
use WP_Theme_JSON_Data;

/** @since 1.0.0 */
final class BlockEditorIntegration
{
    private const HANDLE_EDITOR    = 'gratora-campaign-blocks-editor';
    private const HANDLE_EDITOR_UI = 'gratora-campaign-blocks-editor-ui';
    private const HANDLE_FRONTEND = 'gratora-campaign-blocks';
    private const BUILD_DIR       = 'build/admin/campaign-blocks';

    // Must list every registered campaign block: gates the front-end CSS enqueue.
    private const BLOCK_NAMES = [
        'gratora/campaign-image',
        'gratora/campaign-stat',
        'gratora/campaign-progress',
        'gratora/campaign-grid',
        'gratora/donate-button',
        'gratora/donation-form',
        'gratora/top-donors',
        'gratora/recent-donations',
        'gratora/supporter-wall',
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
     * Expose campaign binding to editor controls through REST.
     *
     * @since 1.0.0
     */
    public const META_TEMPLATE = '_gratora_campaign_page_template';

    public function registerPageMeta(): void
    {
        register_post_meta('page', '_gratora_campaign_id', [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => static fn(): bool => current_user_can('edit_posts'),
        ]);

        // Which template the page's blocks came from. Written by the switcher
        // and saved with the post, so applying a template and then undoing it
        // leaves no record behind, and an add-on that dresses its pages from
        // the choice reads a value the page actually kept.
        register_post_meta('page', self::META_TEMPLATE, [
            'type'          => 'string',
            'single'        => true,
            'default'       => '',
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

    /** Returns 0 when no campaign is bound. */
    private static function editedCampaignId(): int
    {
        $postId = self::editedPostId();

        return $postId > 0 ? (int) get_post_meta($postId, '_gratora_campaign_id', true) : 0;
    }

    /** Returns 0 when no post is open. */
    private static function editedPostId(): int
    {
        $postId = (int) get_the_ID();

        if ($postId <= 0) {
            $postId = intval($_GET['post'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which post the editor is on; nothing is written.
        }

        return max(0, $postId);
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
        // Both routes behind the button want this cap, so offering it to anyone
        // else offers a modal that can only fail, with a Try again that never
        // succeeds and a template choice that 403s after it is made.
        if (! Capabilities::userCan('gratora_manage_campaigns')) {
            return false;
        }

        $campaign = self::editedCampaign();
        if ($campaign === null) {
            return false;
        }

        // Only the campaign's own page. Other pages carry the campaign's id so
        // their blocks can resolve against it, and a template dropped on one of
        // those would replace whatever that page is actually for.
        if ((int) ($campaign->page_id ?? 0) !== self::editedPostId()) {
            return false;
        }

        return (bool) apply_filters(
            'gratora.campaign.supports_page_templates',
            true,
            (string) $campaign->campaign_type,
            $campaign
        );
    }

    /**
     * The type of the campaign the editor is open on, or ''.
     *
     * The switcher asks for it because the list of templates depends on it: a
     * type that lays out its own page has templates of its own, and offering
     * the general ones would replace every block that type exists for.
     *
     * @since 1.0.0
     */
    public static function editedCampaignType(): string
    {
        $campaign = self::editedCampaign();

        return $campaign === null ? '' : (string) $campaign->campaign_type;
    }

    private static function editedCampaign(): ?Campaign
    {
        $campaignId = self::editedCampaignId();

        return $campaignId > 0 ? (new CampaignRepository())->findById($campaignId) : null;
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
            if (($category['slug'] ?? '') === 'gratora') return $categories;
        }
        array_unshift($categories, [
            'slug'  => 'gratora',
            'title' => __('Gratora', 'gratora-donation-platform'),
            'icon'  => 'heart',
        ]);
        return $categories;
    }

    /** @since 1.0.0 */
    public function enqueueEditorAssets(): void
    {
        $assetPath = GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE_EDITOR,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GRATORA_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE_EDITOR, 'gratora-donation-platform', GRATORA_DIR . 'languages');

        // Editor-chrome styles (the layout picker's modal). Kept out of
        // campaign-blocks.css, which the front end also loads, and only sent to
        // the screens that can open the picker: the blocks themselves can be
        // used on any page, but the layout switcher shows on a campaign's own.
        $uiCss = 'build/admin/campaign-blocks-ui.css';
        if (self::pageTemplatesAvailable() && file_exists(GRATORA_DIR . $uiCss)) {
            wp_enqueue_style(
                self::HANDLE_EDITOR_UI,
                GRATORA_URL . $uiCss,
                ['wp-components'],
                (string) filemtime(GRATORA_DIR . $uiCss)
            );
            wp_style_add_data(self::HANDLE_EDITOR_UI, 'rtl', 'replace');
        }

        // The binding picker's field list is handed over rather than repeated in
        // JS, so the labels are translated once and the two halves cannot
        // disagree about which values exist.
        wp_add_inline_script(
            self::HANDLE_EDITOR,
            'window.gratoraCampaignBlocks = Object.assign( window.gratoraCampaignBlocks || {}, '
            . wp_json_encode([
                'bindingFields' => CampaignBindings::fields(),
                'pageTemplates' => self::pageTemplatesAvailable(),
                'campaignType'  => self::editedCampaignType(),
                // The blocks register for every block-editor user, but the
                // campaign list is gated. Without this the editor reads a
                // refused fetch as a campaign that no longer exists.
                'canManageCampaigns' => Capabilities::userCan('gratora_manage_campaigns'),
            ]) . ' );',
            'before'
        );
    }

    /**
     * enqueue_block_assets reaches the iframed editor canvas.
     *
     * @since 1.0.0
     */
    public function enqueueEditorCanvasStyle(): void
    {
        if (! is_admin()) {
            return;
        }
        $cssPath = GRATORA_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                GRATORA_URL . 'build/admin/campaign-blocks.css',
                [],
                // Use mtime to invalidate unreleased CSS changes.
                (string) (@filemtime($cssPath) ?: GRATORA_VERSION)
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
                if ($name === 'gratora/donate-button') {
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
     * has_block() only sees the post's own content, so a Gratora block nested in a
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
        if ($name === 'gratora/donate-button') {
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
        $cssPath = GRATORA_DIR . 'build/admin/campaign-blocks.css';
        if (file_exists($cssPath)) {
            wp_enqueue_style(
                self::HANDLE_FRONTEND,
                GRATORA_URL . 'build/admin/campaign-blocks.css',
                [],
                (string) (@filemtime($cssPath) ?: GRATORA_VERSION)
            );
            wp_style_add_data(self::HANDLE_FRONTEND, 'rtl', 'replace');
        }
    }

    /** @since 1.0.0 */
    private function enqueueDonateButtonModal(): void
    {
        if (wp_script_is('gratora-donate-button-modal', 'enqueued')) {
            return;
        }
        wp_enqueue_script(
            'gratora-donate-button-modal',
            GRATORA_URL . 'assets/donate-button/modal.js',
            [],
            GRATORA_VERSION,
            true
        );
    }
}
