<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Funds admin page.
 *
 * @since 1.0.0
 */
final class FundsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-funds';
    private const HANDLE    = 'dono-admin-funds';
    private const BUILD_DIR = 'build/admin/funds';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Funds', 'dono-fundraising-platform'),
            'capability' => 'dono_access_campaigns',
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
            <div id="dono-admin-funds"></div>
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
            'dono-dataviews-vendor-funds',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
        wp_enqueue_style(
            'dono-admin-funds',
            DONO_URL . 'build/admin/funds.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
