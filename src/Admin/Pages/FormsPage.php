<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;
use Dono\Foundation\Plugin;
use Dono\Donors\ConsentService;
use Dono\Gateways\GatewayManager;

/**
 * Registers and renders the Forms admin page, including full-screen editor mode.
 *
 * @version 1.0.0
 */
final class FormsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-forms';
    private const HANDLE    = 'dono-admin-forms';
    private const BUILD_DIR = 'build/admin/forms';

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    protected function actions(): array
    {
        return ['admin_head' => 'maybePrintFullscreenStyles'];
    }

    /**
     * True when the forms screen is showing an editor. The page is hidden and
     * has no other view, so the form id is the only signal that matters - the
     * React root gates on the same thing, which keeps the fullscreen chrome and
     * the editor from disagreeing about what is on screen.
     */
    public static function isFormEditView(): bool
    {
        return is_admin()
            && ($_GET['page'] ?? '') === self::PAGE_ID
            && (int) ($_GET['form'] ?? 0) > 0;
    }

    public function maybePrintFullscreenStyles(): void
    {
        if (! self::isFormEditView()) return;
        echo '<style id="dono-fullscreen-editor">'
            . '#wpadminbar,#adminmenumain,#adminmenuwrap,#adminmenuback,#wpfooter,.notice,.update-nag,h1.wp-heading-inline,.wp-header-end{display:none!important}'
            . 'html.wp-toolbar{padding-top:0!important}'
            . 'html,body{height:100%;margin:0;padding:0;background:#fff}'
            . '#wpwrap,#wpcontent,#wpbody,#wpbody-content{margin-left:0!important;padding:0!important;float:none!important;width:100%!important;background:#fff}'
            . '.wrap,.dono-forms-wrap{margin:0!important;padding:0!important}'
            . '#dono-admin-forms{height:100vh;overflow:hidden;background:#fff}'
            . '</style>';
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Forms', 'dono'),
            'capability' => 'dono_access_forms',
            'position'   => 15,
            'hidden'     => true,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    public function render(): void
    {
        $this->bootBlockEditorContext();
        $this->enqueueAssets();
        ?>
        <div class="wrap dono-forms-wrap">
            <div id="dono-admin-forms"></div>
        </div>
        <?php
    }

    private function bootBlockEditorContext(): void
    {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $GLOBALS['hook_suffix'] = '';

        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/post.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

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

        $asset = require DONO_DIR . self::BUILD_DIR . '/index.asset.php';
        wp_enqueue_script(
            self::HANDLE,
            DONO_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? DONO_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');

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

        wp_localize_script(self::HANDLE, 'donoFormsEditor', [
            'gateways' => $gateways,
            'consents' => $consents,
            'consentsSettingsUrl' => admin_url('admin.php?page=dono-settings&tab=consents'),
        ]);

        do_action('dono.editor.assets', self::HANDLE);

        wp_enqueue_style(
            'dono-dataviews-vendor-forms',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );

        wp_enqueue_style(
            'dono-admin-forms',
            DONO_URL . 'build/admin/forms.css',
            ['wp-edit-post', 'wp-block-editor', 'wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
