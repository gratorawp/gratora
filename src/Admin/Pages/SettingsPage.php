<?php

declare(strict_types=1);

namespace FundKit\Admin\Pages;

use FundKit\Admin\ExtensionAssets;
use FundKit\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Settings admin page.
 *
 * @since 1.0.0
 */
final class SettingsPage extends HookProvider
{
    private const PAGE_ID   = 'fundkit-settings';
    private const HANDLE    = 'fundkit-admin-settings';
    private const BUILD_DIR = 'build/admin/settings';

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
            'title'      => __('Settings', 'fundraising-toolkit'),
            'capability' => 'fundkit_access_settings',
            'position'   => 90,
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
            <?php // Anchor for WP's admin-notice mover: without a server-rendered
                  // heading (the "Settings" h1 is React-rendered), notices land
                  // inside the React header row. This pins them above it. ?>
            <hr class="wp-header-end" />
            <div id="fundkit-admin-settings"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = FUNDKIT_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
        $asset = require $assetPath;

        wp_enqueue_media();

        // Add-ons register their settings tab into this registry, so it has to
        // be defined before the settings app reads it.
        ExtensionAssets::enqueue('settings');

        wp_enqueue_script(
            self::HANDLE,
            FUNDKIT_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            FUNDKIT_URL . 'build/admin/settings.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/settings.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
