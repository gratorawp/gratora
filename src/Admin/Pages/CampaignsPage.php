<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Admin\ExtensionAssets;
use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Campaigns admin page.
 *
 * @version 1.0.0
 */
final class CampaignsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-campaigns';
    private const HANDLE    = 'dono-admin-campaigns';
    private const BUILD_DIR = 'build/admin/campaigns';

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Campaigns', 'dono'),
            'capability' => 'dono_access_campaigns',
            'position'   => 5,
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
            <div id="dono-admin-campaigns"></div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $asset = require DONO_DIR . self::BUILD_DIR . '/index.asset.php';

        wp_enqueue_media();

        // Register the extension-tab registry and let add-ons enqueue their
        // campaign tab bundles, then depend on it so the registry is defined
        // before the app reads it.
        ExtensionAssets::enqueue('campaign');
        ExtensionAssets::enqueue('campaign-settings');

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
            'dono-dataviews-vendor-campaigns',
            DONO_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
        wp_enqueue_style(
            'dono-admin-campaigns',
            DONO_URL . 'build/admin/campaigns.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
