<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Admin\ExtensionAssets;
use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class SettingsPage extends HookProvider
{
    private const PAGE_ID   = 'gratora-settings';
    private const HANDLE    = 'gratora-admin-settings';
    private const BUILD_DIR = 'build/admin/settings';

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
            'title'      => __('Settings', 'gratora-donation-platform'),
            'capability' => 'gratora_access_settings',
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
            <?php // Keep WordPress notices above the React header.?>
            <hr class="wp-header-end" />
            <div id="gratora-admin-settings"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;
        $asset = require $assetPath;

        wp_enqueue_media();

        // Initialize the extension registry before the settings app.
        ExtensionAssets::enqueue('settings');

        wp_enqueue_script(
            self::HANDLE,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? GRATORA_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE, 'gratora-donation-platform', GRATORA_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            GRATORA_URL . 'build/admin/settings.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/settings.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
