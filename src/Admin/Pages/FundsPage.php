<?php

declare(strict_types=1);

namespace GiveFlow\Admin\Pages;

use GiveFlow\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Funds admin page.
 *
 * @since 1.0.0
 */
final class FundsPage extends HookProvider
{
    private const PAGE_ID   = 'giveflow-funds';
    private const HANDLE    = 'giveflow-admin-funds';
    private const BUILD_DIR = 'build/admin/funds';

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
            'title'      => __('Funds', 'giveflow-fundraising-campaigns'),
            'capability' => 'giveflow_access_campaigns',
            'position'   => 25,
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
            <div id="giveflow-admin-funds"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require GIVEFLOW_DIR . self::BUILD_DIR . '/index.asset.php';

        wp_enqueue_script(
            self::HANDLE,
            GIVEFLOW_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'giveflow-dataviews-vendor-funds',
            GIVEFLOW_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . self::BUILD_DIR . '/dataviews.css') ?: GIVEFLOW_VERSION)
        );
        wp_enqueue_style(
            'giveflow-admin-funds',
            GIVEFLOW_URL . 'build/admin/funds.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/funds.css') ?: GIVEFLOW_VERSION)
        );
    }
}
