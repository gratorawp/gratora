<?php

declare(strict_types=1);

namespace Gratora\Admin\Pages;

use Gratora\Admin\ExtensionAssets;
use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class DonorsPage extends HookProvider
{
    private const PAGE_ID   = 'gratora-donors';
    private const HANDLE    = 'gratora-admin-donors';
    private const BUILD_DIR = 'build/admin/donors';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['gratora.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Donors', 'gratora'),
            'capability' => 'gratora_access_donors',
            'position'   => 20,
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
            <div id="gratora-admin-donors"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';

        ExtensionAssets::enqueue('donor');

        wp_enqueue_script(
            self::HANDLE,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? GRATORA_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'gratora', GRATORA_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'gratora-dataviews-vendor-donors',
            GRATORA_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . self::BUILD_DIR . '/dataviews.css') ?: GRATORA_VERSION)
        );
        wp_enqueue_style(
            'gratora-admin-donors',
            GRATORA_URL . 'build/admin/donors.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/donors.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data('gratora-admin-donors', 'rtl', 'replace');
    }
}
