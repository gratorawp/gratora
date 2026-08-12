<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Subscriptions admin page: recurring plans across
 * the whole book, not just the donor who owns one.
 *
 * @since 1.0.0
 */
final class SubscriptionsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-subscriptions';
    private const HANDLE    = 'dono-admin-subscriptions';
    private const BUILD_DIR = 'build/admin/subscriptions';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'    => self::PAGE_ID,
            'title' => __('Subscriptions', 'dono-fundraising-platform'),
            // Reading the list is a donations-level view; changing a plan is
            // gated separately on the REST route that does it.
            'capability' => 'dono_access_donations',
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
            <div id="dono-admin-subscriptions"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require DONO_DIR . self::BUILD_DIR . '/index.asset.php';

        wp_enqueue_script(
            self::HANDLE,
            DONO_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? DONO_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'dono-fundraising-platform', DONO_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'dono-dataviews-vendor-subscriptions',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
        wp_enqueue_style(
            'dono-admin-subscriptions',
            DONO_URL . 'build/admin/subscriptions.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
