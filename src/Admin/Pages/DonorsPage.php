<?php

declare(strict_types=1);

namespace GiveFlow\Admin\Pages;

use GiveFlow\Foundation\Hooks\HookProvider;
use GiveFlow\Admin\ExtensionAssets;

/**
 * Registers and renders the Donors admin page.
 *
 * @since 1.0.0
 */
final class DonorsPage extends HookProvider
{
    private const PAGE_ID   = 'giveflow-donors';
    private const HANDLE    = 'giveflow-admin-donors';
    private const BUILD_DIR = 'build/admin/donors';

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
            'title'      => __('Donors', 'giveflow-fundraising-campaigns'),
            'capability' => 'giveflow_access_donors',
            'position'   => 20,
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
            <?php // WP moves admin notices to just after this marker. Without it they
                  // land beside the React header instead of above it. ?>
            <hr class="wp-header-end" />
            <div id="giveflow-admin-donors"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require GIVEFLOW_DIR . self::BUILD_DIR . '/index.asset.php';

        ExtensionAssets::enqueue('donor');

        wp_enqueue_script(
            self::HANDLE,
            GIVEFLOW_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'giveflow-dataviews-vendor-donors',
            GIVEFLOW_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . self::BUILD_DIR . '/dataviews.css') ?: GIVEFLOW_VERSION)
        );
        wp_enqueue_style(
            'giveflow-admin-donors',
            GIVEFLOW_URL . 'build/admin/donors.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/donors.css') ?: GIVEFLOW_VERSION)
        );
    }
}
