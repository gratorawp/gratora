<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Tools admin page.
 *
 * @version 1.0.0
 */
final class ToolsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-tools';
    private const HANDLE    = 'dono-admin-tools';
    private const BUILD_DIR = 'build/admin/tools';

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Tools', 'dono'),
            'capability' => 'manage_dono',
            // After Settings: this is where someone goes once they already know
            // what they are looking for.
            'position'   => 95,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

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

        wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'dono-admin-tools',
            DONO_URL . 'build/admin/tools.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
