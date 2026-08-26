<?php

declare(strict_types=1);

namespace GiveFlow\Admin\Pages;

use GiveFlow\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Tools admin page.
 *
 * @since 1.0.0
 */
final class ToolsPage extends HookProvider
{
    private const PAGE_ID   = 'giveflow-tools';
    private const HANDLE    = 'giveflow-admin-tools';
    private const BUILD_DIR = 'build/admin/tools';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['giveflow.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Tools', 'giveflow-fundraising-campaigns'),
            'capability' => 'manage_giveflow',
            // After Settings: this is where someone goes once they already know
            // what they are looking for.
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
            <div id="giveflow-admin-tools"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = GIVEFLOW_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            GIVEFLOW_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');

        wp_enqueue_style('wp-components');
        // The list is a DataViews table, and its own layout CSS is a vendor file
        // rather than anything the theme or wp-components provides.
        wp_enqueue_style(
            'giveflow-dataviews-vendor-tools',
            GIVEFLOW_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . self::BUILD_DIR . '/dataviews.css') ?: GIVEFLOW_VERSION)
        );
        wp_enqueue_style(
            'giveflow-admin-tools',
            GIVEFLOW_URL . 'build/admin/tools.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/tools.css') ?: GIVEFLOW_VERSION)
        );
    }
}
