<?php

declare(strict_types=1);

namespace FundKit\Admin\Pages;

use FundKit\Foundation\Hooks\HookProvider;

/**
 * Registers and renders the Funds admin page.
 *
 * @since 1.0.0
 */
final class FundsPage extends HookProvider
{
    private const PAGE_ID   = 'fundkit-funds';
    private const HANDLE    = 'fundkit-admin-funds';
    private const BUILD_DIR = 'build/admin/funds';

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
            'title'      => __('Funds', 'fundraising-toolkit'),
            'capability' => 'fundkit_access_campaigns',
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
            <div id="fundkit-admin-funds"></div>
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
            'fundkit-dataviews-vendor-funds',
            FUNDKIT_URL . self::BUILD_DIR . '/dataviews.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . self::BUILD_DIR . '/dataviews.css') ?: FUNDKIT_VERSION)
        );
        wp_enqueue_style(
            'fundkit-admin-funds',
            FUNDKIT_URL . 'build/admin/funds.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/funds.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data('fundkit-admin-funds', 'rtl', 'replace');
    }
}
