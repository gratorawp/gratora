<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Admin\ExtensionAssets;
use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class CampaignsPage extends HookProvider
{
    private const PAGE_ID   = 'gratora-campaigns';
    private const HANDLE    = 'gratora-admin-campaigns';
    private const BUILD_DIR = 'build/admin/campaigns';

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
            'title'      => __('Campaigns', 'gratora-donation-platform'),
            'capability' => 'gratora_access_campaigns',
            'position'   => 5,
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
            <div id="gratora-admin-campaigns"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';

        wp_enqueue_media();

        // Initialize the extension registry before the app.
        ExtensionAssets::enqueue('campaign');
        ExtensionAssets::enqueue('campaign-settings');

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
            'gratora-dataviews-vendor-campaigns',
            GRATORA_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . self::BUILD_DIR . '/dataviews.css') ?: GRATORA_VERSION)
        );
        wp_enqueue_style(
            'gratora-admin-campaigns',
            GRATORA_URL . 'build/admin/campaigns.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/campaigns.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data('gratora-admin-campaigns', 'rtl', 'replace');
    }
}
