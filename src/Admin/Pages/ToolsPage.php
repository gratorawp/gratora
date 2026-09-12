<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class ToolsPage extends HookProvider
{
    private const PAGE_ID   = 'gratora-tools';
    private const HANDLE    = 'gratora-admin-tools';
    private const BUILD_DIR = 'build/admin/tools';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['gratora.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Tools', 'gratora-donation-platform'),
            // Every Tools route wants gratora_manage_settings; this virtual menu cap
            // is granted on exactly that (or manage_options), so what the menu shows
            // and what the screen can do agree.
            'capability' => 'gratora_access_settings',
            'position'   => 95,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <hr class="wp-header-end" />
            <div id="gratora-admin-tools"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GRATORA_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'gratora-donation-platform', GRATORA_DIR . 'languages');

        wp_enqueue_style('wp-components');
        // DataViews layout requires its vendor stylesheet.
        wp_enqueue_style(
            'gratora-dataviews-vendor-tools',
            GRATORA_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . self::BUILD_DIR . '/dataviews.css') ?: GRATORA_VERSION)
        );
        wp_enqueue_style(
            'gratora-admin-tools',
            GRATORA_URL . 'build/admin/tools.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/tools.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data('gratora-admin-tools', 'rtl', 'replace');
    }
}
