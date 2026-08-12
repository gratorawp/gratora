<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Tools admin page.
 *
 * @since 1.0.0
 */
final class ToolsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-tools';
    private const HANDLE    = 'dono-admin-tools';
    private const BUILD_DIR = 'build/admin/tools';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Tools', 'dono-fundraising-platform'),
            'capability' => 'manage_dono',
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
            <div id="dono-admin-tools"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = DONO_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) {
            return;
        }

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            DONO_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? DONO_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'dono-fundraising-platform', DONO_DIR . 'languages');

        wp_enqueue_style('wp-components');
        // The list is a DataViews table, and its own layout CSS is a vendor file
        // rather than anything the theme or wp-components provides.
        wp_enqueue_style(
            'dono-dataviews-vendor-tools',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
        wp_enqueue_style(
            'dono-admin-tools',
            DONO_URL . 'build/admin/tools.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
