<?php

declare(strict_types=1);

namespace GiveFlow\Admin\Pages;

use GiveFlow\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Subscriptions admin page: recurring plans across
 * the whole book, not just the donor who owns one.
 *
 * @since 1.0.0
 */
final class SubscriptionsPage extends HookProvider
{
    private const PAGE_ID   = 'giveflow-subscriptions';
    private const HANDLE    = 'giveflow-admin-subscriptions';
    private const BUILD_DIR = 'build/admin/subscriptions';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['giveflow.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'    => self::PAGE_ID,
            'title' => __('Subscriptions', 'giveflow-fundraising-campaigns'),
            // Reading the list is a donations-level view; changing a plan is
            // gated separately on the REST route that does it.
            'capability' => 'giveflow_access_donations',
            'position'   => 15,
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
            <div id="giveflow-admin-subscriptions"></div>
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
            'giveflow-dataviews-vendor-subscriptions',
            GIVEFLOW_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . self::BUILD_DIR . '/dataviews.css') ?: GIVEFLOW_VERSION)
        );
        wp_enqueue_style(
            'giveflow-admin-subscriptions',
            GIVEFLOW_URL . 'build/admin/subscriptions.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/subscriptions.css') ?: GIVEFLOW_VERSION)
        );
    }
}
