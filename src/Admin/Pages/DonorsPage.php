<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;
use Dono\Admin\ExtensionAssets;

/**
 * Registers and renders the Donors admin page.
 *
 * @version 1.0.0
 */
final class DonorsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-donors';
    private const HANDLE    = 'dono-admin-donors';
    private const BUILD_DIR = 'build/admin/donors';

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Donors', 'dono'),
            'capability' => 'dono_access_donors',
            'position'   => 20,
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
            <div id="dono-admin-donors"></div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $asset = require DONO_DIR . self::BUILD_DIR . '/index.asset.php';

        ExtensionAssets::enqueue('donor');

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
            'dono-dataviews-vendor-donors',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
        wp_enqueue_style(
            'dono-admin-donors',
            DONO_URL . 'build/admin/donors.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
