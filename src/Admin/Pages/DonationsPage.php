<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Admin\ExtensionAssets;
use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Donations admin page.
 *
 * @version 1.0.0
 */
final class DonationsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-donations';
    private const HANDLE    = 'dono-admin-donations';
    private const BUILD_DIR = 'build/admin/donations';

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Donations', 'dono'),
            'capability' => 'dono_access_donations',
            'position'   => 10,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <?php // WP moves admin notices to just after this marker. Without it they
                  // land beside the React header instead of above it. ?>
            <hr class="wp-header-end" />
            <div id="dono-admin-donations"></div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $asset = require DONO_DIR . self::BUILD_DIR . '/index.asset.php';

        // Register the extension-panel registry and let add-ons enqueue their
        // donation bundles, then depend on it so the registry is defined
        // before the app reads it.
        ExtensionAssets::enqueue('donation');

        wp_enqueue_script(
            self::HANDLE,
            DONO_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? DONO_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'dono-dataviews-vendor',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
        wp_enqueue_style(
            'dono-admin-donations',
            DONO_URL . 'build/admin/donations.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
