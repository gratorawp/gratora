<?php

declare(strict_types=1);

namespace FundKit\Admin\Pages;

use FundKit\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Tools admin page.
 *
 * @since 1.0.0
 */
final class ToolsPage extends HookProvider
{
    private const PAGE_ID   = 'fundkit-tools';
    private const HANDLE    = 'fundkit-admin-tools';
    private const BUILD_DIR = 'build/admin/tools';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['fundkit.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Tools', 'fundraising-toolkit'),
            'capability' => 'manage_fundkit',
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
            <div id="fundkit-admin-tools"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = FUNDKIT_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            FUNDKIT_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        // The list is a DataViews table, and its own layout CSS is a vendor file
        // rather than anything the theme or wp-components provides.
        wp_enqueue_style(
            'fundkit-dataviews-vendor-tools',
            FUNDKIT_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . self::BUILD_DIR . '/dataviews.css') ?: FUNDKIT_VERSION)
        );
        wp_enqueue_style(
            'fundkit-admin-tools',
            FUNDKIT_URL . 'build/admin/tools.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/tools.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data('fundkit-admin-tools', 'rtl', 'replace');
    }
}
