<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Container\Container;
use Dono\Admin\ExtensionAssets;
use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Settings admin page.
 *
 * @version 1.0.0
 */
final class SettingsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-settings';
    private const HANDLE    = 'dono-admin-settings';
    private const BUILD_DIR = 'build/admin/settings';

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
            'title'      => __('Settings', 'dono'),
            'capability' => 'dono_access_settings',
            'position'   => 90,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <?php // Anchor for WP's admin-notice mover: without a server-rendered
                  // heading (the "Settings" h1 is React-rendered), notices land
                  // inside the React header row. This pins them above it. ?>
            <hr class="wp-header-end" />
            <div id="dono-admin-settings"></div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $assetPath = DONO_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
        $asset = require $assetPath;

        wp_enqueue_media();

        // Add-ons register their settings tab into this registry, so it has to
        // be defined before the settings app reads it.
        ExtensionAssets::enqueue('settings');

        wp_enqueue_script(
            self::HANDLE,
            DONO_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? DONO_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            DONO_URL . 'build/admin/settings.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
