<?php

declare(strict_types=1);

namespace FundKit\Admin\Pages;

use FundKit\Admin\ExtensionAssets;
use FundKit\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class DonorsPage extends HookProvider
{
    private const PAGE_ID   = 'fundkit-donors';
    private const HANDLE    = 'fundkit-admin-donors';
    private const BUILD_DIR = 'build/admin/donors';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['fundkit.admin.pages' => 'registerPage'];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Donors', 'fundraising-toolkit'),
            'capability' => 'fundkit_access_donors',
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
            <div id="fundkit-admin-donors"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $asset = require FUNDKIT_DIR . self::BUILD_DIR . '/index.asset.php';

        ExtensionAssets::enqueue('donor');

        wp_enqueue_script(
            self::HANDLE,
            FUNDKIT_URL . self::BUILD_DIR . '/index.js',
            array_merge($asset['dependencies'] ?? [], [ExtensionAssets::HANDLE]),
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            'fundkit-dataviews-vendor-donors',
            FUNDKIT_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . self::BUILD_DIR . '/dataviews.css') ?: FUNDKIT_VERSION)
        );
        wp_enqueue_style(
            'fundkit-admin-donors',
            FUNDKIT_URL . 'build/admin/donors.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/donors.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data('fundkit-admin-donors', 'rtl', 'replace');
    }
}
