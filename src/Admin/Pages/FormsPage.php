<?php

declare(strict_types=1);

namespace GiveFlow\Admin\Pages;

use GiveFlow\Foundation\Hooks\HookProvider;
use GiveFlow\Foundation\Plugin;
use GiveFlow\Donors\ConsentService;
use GiveFlow\Gateways\GatewayManager;

/**
 * Registers and renders the Forms admin page, including full-screen editor mode.
 *
 * @since 1.0.0
 */
final class FormsPage extends HookProvider
{
    private const PAGE_ID   = 'giveflow-forms';
    private const HANDLE    = 'giveflow-admin-forms';
    private const BUILD_DIR = 'build/admin/forms';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['giveflow.admin.pages' => 'registerPage'];
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
        . '.wrap,.giveflow-forms-wrap{margin:0!important;padding:0!important}'
        . '#giveflow-admin-forms{height:100vh;overflow:hidden;background:#fff}';

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Forms', 'giveflow-fundraising-campaigns'),
            'capability' => 'giveflow_access_forms',
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
        <div class="wrap giveflow-forms-wrap">
            <div id="giveflow-admin-forms"></div>
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

        $asset = require GIVEFLOW_DIR . self::BUILD_DIR . '/index.asset.php';
        wp_enqueue_script(
            self::HANDLE,
            GIVEFLOW_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');

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

        wp_localize_script(self::HANDLE, 'giveflowFormsEditor', [
            'gateways' => $gateways,
            'consents' => $consents,
            'consentsSettingsUrl' => admin_url('admin.php?page=giveflow-settings&tab=consents'),
        ]);

        do_action('giveflow.editor.assets', self::HANDLE);

        wp_enqueue_style(
            'giveflow-dataviews-vendor-forms',
            GIVEFLOW_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . self::BUILD_DIR . '/dataviews.css') ?: GIVEFLOW_VERSION)
        );

        wp_enqueue_style(
            'giveflow-admin-forms',
            GIVEFLOW_URL . 'build/admin/forms.css',
            ['wp-edit-post', 'wp-block-editor', 'wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/forms.css') ?: GIVEFLOW_VERSION)
        );

        if (self::isFormEditView()) {
            wp_add_inline_style('giveflow-admin-forms', self::FULLSCREEN_CSS);
        }
    }
}
