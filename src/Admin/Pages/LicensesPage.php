<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Container\Container;
use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Licenses admin page.
 *
 * @version 1.0.0
 */
final class LicensesPage extends HookProvider
{
    private const PAGE_ID   = 'dono-licenses';
    private const HANDLE    = 'dono-admin-licenses';
    private const BUILD_DIR = 'build/admin/licenses';

    public function __construct(private Container $container)
    {
    }

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Licenses', 'dono'),
            'capability' => 'dono_access_settings',
            'position'   => 85,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <?php // Anchor for WP's admin-notice mover: the React header renders
                  // the "Licenses" h1, so this pins notices above it. ?>
            <hr class="wp-header-end" />
            <div id="dono-admin-licenses"></div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $assetPath = DONO_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
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
            self::HANDLE,
            DONO_URL . 'build/admin/licenses.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
