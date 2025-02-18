<?php

declare(strict_types=1);

namespace Dono\Admin\Pages;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Funds admin page.
 *
 * @version 1.0.0
 */
final class FundsPage extends HookProvider
{
    private const PAGE_ID   = 'dono-funds';
    private const HANDLE    = 'dono-admin-funds';
    private const BUILD_DIR = 'build/admin/funds';

    protected function filters(): array
    {
        return ['dono.admin.pages' => 'registerPage'];
    }

    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Funds', 'dono'),
            'capability' => 'dono_access_campaigns',
            'position'   => 25,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <div id="dono-admin-funds"></div>
        </div>
        <?php
    }

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

        wp_set_script_translations(self::HANDLE, 'dono', DONO_DIR . 'languages');

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
