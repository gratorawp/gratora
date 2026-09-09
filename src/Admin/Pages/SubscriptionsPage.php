<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class SubscriptionsPage extends HookProvider
{
    private const PAGE_ID   = 'gratora-subscriptions';
    private const HANDLE    = 'gratora-admin-subscriptions';
    private const BUILD_DIR = 'build/admin/subscriptions';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['gratora.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'    => self::PAGE_ID,
            'title' => __('Subscriptions', 'gratora'),
            // Listing needs donations access; mutations check their own permissions.
            'capability' => 'gratora_access_donations',
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
            <div id="gratora-admin-subscriptions"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';

        wp_enqueue_script(
            self::HANDLE,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GRATORA_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'gratora', GRATORA_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'gratora-dataviews-vendor-subscriptions',
            GRATORA_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . self::BUILD_DIR . '/dataviews.css') ?: GRATORA_VERSION)
        );
        wp_enqueue_style(
            'gratora-admin-subscriptions',
            GRATORA_URL . 'build/admin/subscriptions.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/subscriptions.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data('gratora-admin-subscriptions', 'rtl', 'replace');
    }
}
