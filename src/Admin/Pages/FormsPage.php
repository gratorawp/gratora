<?php

declare(strict_types=1);

namespace FundKit\Admin\Pages;

use FundKit\Foundation\Hooks\HookProvider;
use FundKit\Foundation\Plugin;
use FundKit\Donors\ConsentService;
use FundKit\Gateways\GatewayManager;

/**
 * Registers and renders the Forms admin page, including full-screen editor mode.
 *
 * @since 1.0.0
 */
final class FormsPage extends HookProvider
{
    private const PAGE_ID   = 'fundkit-forms';
    private const HANDLE    = 'fundkit-admin-forms';
    private const BUILD_DIR = 'build/admin/forms';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            'fundkit.admin.pages' => 'registerPage',
            'show_admin_bar'      => 'hideAdminBar',
        ];
    }

    /**
     * The editor is fullscreen, and the bar is chrome it does not have room
     * for. FULLSCREEN_CSS hides it too, but only after it has rendered and
     * pushed the page down; refusing it here means it never does.
     *
     * @param bool $show
     * @since 1.0.0
     */
    public function hideAdminBar($show)
    {
        return self::isFormEditView() ? false : $show;
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [];
    }

    /**
     * True when the forms screen is showing an editor. The page is hidden and
     * has no other view, so the form id is the only signal that matters, and
     * the React root gates on the same thing, which keeps the fullscreen chrome
     * and the editor from disagreeing about what is on screen.
     *
     * @since 1.0.0
     */
    public static function isFormEditView(): bool
    {
        return is_admin()
            && (isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '') === self::PAGE_ID
            && intval($_GET['form'] ?? 0) > 0;
    }

    /**
     * The chrome the editor hides. Attached to the screen's own stylesheet
     * rather than printed, because a <style> tag in admin_head is not
     * enqueueable and the handle below is already on this screen.
     */
    private const FULLSCREEN_CSS =
        '#wpadminbar,#adminmenumain,#adminmenuwrap,#adminmenuback,#wpfooter,.notice,.update-nag,h1.wp-heading-inline,.wp-header-end{display:none!important}'
        . 'html.wp-toolbar{padding-top:0!important}'
        . 'html,body{height:100%;margin:0;padding:0;background:#fff}'
        . '#wpwrap,#wpcontent,#wpbody,#wpbody-content{margin-left:0!important;padding:0!important;float:none!important;width:100%!important;background:#fff}'
        . '.wrap,.fundkit-forms-wrap{margin:0!important;padding:0!important}'
        . '#fundkit-admin-forms{height:100vh;overflow:hidden;background:#fff}';

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Forms', 'fundraising-toolkit'),
            'capability' => 'fundkit_access_forms',
            'position'   => 15,
            'hidden'     => true,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        $this->bootBlockEditorContext();
        $this->enqueueAssets();
        ?>
        <div class="wrap fundkit-forms-wrap">
            <div id="fundkit-admin-forms"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function bootBlockEditorContext(): void
    {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP_Screen::get() reads $hook_suffix as the screen id, so blanking it gives set_current_screen() below a neutral screen instead of this page's.
        $GLOBALS['hook_suffix'] = '';

        // render() is a menu page callback, so wp-admin/admin.php has already
        // loaded screen, post and media through includes/admin.php by the time
        // this runs. Requiring them again was redundant.
        set_current_screen();
        $screen = get_current_screen();
        if ($screen && method_exists($screen, 'is_block_editor')) {
            $screen->is_block_editor(true);
        }

        add_filter('block_editor_settings_all', static function (array $settings): array {
            $settings['__experimentalBlockPatterns']          = [];
            $settings['__experimentalBlockPatternCategories'] = [];
            $settings['availableLegacyWidgets']               = (object) [];
            $settings['hasPermissionsToManageWidgets']        = false;
            return $settings;
        }, 99);
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        wp_enqueue_script('wp-block-library');
        wp_enqueue_script('wp-format-library');
        wp_enqueue_script('wp-editor');

        wp_enqueue_style('wp-edit-post');
        wp_enqueue_style('wp-format-library');
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-block-editor');
        wp_enqueue_style('wp-components');
        wp_enqueue_style('wp-editor');

        wp_enqueue_media();
        wp_tinymce_inline_scripts();
        wp_enqueue_editor();

        do_action('enqueue_block_editor_assets');
        add_action('admin_print_footer_scripts', ['_WP_Editors', 'print_default_editor_scripts'], 45);

        $asset = require FUNDKIT_DIR . self::BUILD_DIR . '/index.asset.php';
        wp_enqueue_script(
            self::HANDLE,
            FUNDKIT_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        // Registered gateways so the payment-gateways block can list them,
        // each carrying whether the org is currently offering it: a gateway
        // switched off in Settings still belongs in the list, or the block
        // silently drops a choice the author made and cannot see why.
        $manager  = Plugin::instance()->container->get(GatewayManager::class);
        $gateways = [];
        foreach ($manager->all() as $g) {
            $gateways[] = [
                'id'      => $g->id(),
                'label'   => $g->label(),
                'enabled' => $manager->isOn($g->id()),
            ];
        }
        // The consent block picks from these rather than defining purposes of
        // its own, so the editor needs the registry the front end will resolve.
        $consents = array_map(
            static fn (array $p): array => [
                'key'         => $p['key'],
                'label'       => $p['label'],
                'description' => $p['description'],
                'required'    => $p['required'],
            ],
            Plugin::instance()->container->get(ConsentService::class)->purposes()
        );

        wp_localize_script(self::HANDLE, 'fundkitFormsEditor', [
            'gateways' => $gateways,
            'consents' => $consents,
            'consentsSettingsUrl' => admin_url('admin.php?page=fundkit-settings&tab=consents'),
        ]);

        do_action('fundkit.editor.assets', self::HANDLE);

        wp_enqueue_style(
            'fundkit-dataviews-vendor-forms',
            FUNDKIT_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . self::BUILD_DIR . '/dataviews.css') ?: FUNDKIT_VERSION)
        );

        wp_enqueue_style(
            'fundkit-admin-forms',
            FUNDKIT_URL . 'build/admin/forms.css',
            ['wp-edit-post', 'wp-block-editor', 'wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/forms.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data('fundkit-admin-forms', 'rtl', 'replace');

        if (self::isFormEditView()) {
            wp_add_inline_style('fundkit-admin-forms', self::FULLSCREEN_CSS);
        }
    }
}
