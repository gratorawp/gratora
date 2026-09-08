<?php

declare(strict_types=1);

namespace FundKit\Admin\Pages;

use FundKit\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class SubscriptionsPage extends HookProvider
{
    private const PAGE_ID   = 'fundkit-subscriptions';
    private const HANDLE    = 'fundkit-admin-subscriptions';
    private const BUILD_DIR = 'build/admin/subscriptions';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['fundkit.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'    => self::PAGE_ID,
            'title' => __('Subscriptions', 'fundraising-toolkit'),
            // Listing needs donations access; mutations check their own permissions.
            'capability' => 'fundkit_access_donations',
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
            <?php // Keep WordPress notices above the React header.?>
            <hr class="wp-header-end" />
            <div id="fundkit-admin-subscriptions"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require FUNDKIT_DIR . self::BUILD_DIR . '/index.asset.php';

        wp_enqueue_script(
            self::HANDLE,
            FUNDKIT_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'fundkit-dataviews-vendor-subscriptions',
            FUNDKIT_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . self::BUILD_DIR . '/dataviews.css') ?: FUNDKIT_VERSION)
        );
        wp_enqueue_style(
            'fundkit-admin-subscriptions',
            FUNDKIT_URL . 'build/admin/subscriptions.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/subscriptions.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data('fundkit-admin-subscriptions', 'rtl', 'replace');
    }
}
