<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Hooks\HookProvider;

/**
 * Registers the FundKit top-level admin menu and its dynamic subpages.
 *
 * @since 1.0.0
 */
final class AdminMenu extends HookProvider
{
    private const CAPABILITY = 'fundkit_access';
    private const SLUG       = 'fundkit';
    private const HANDLE     = 'fundkit-admin-dashboard';
    private const BUILD_DIR  = 'build/admin/dashboard';

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'admin_menu'            => 'registerMenu',
            'admin_enqueue_scripts' => 'enqueueCommandPalette',
        ];
    }

    /** @since 1.0.0 */
    public function registerMenu(): void
    {
        add_menu_page(
            __('FundKit', 'fundkit-fundraising-campaigns'),
            __('FundKit', 'fundkit-fundraising-campaigns'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard'],
            self::menuIcon(),
            30
        );

        // add_menu_page mints a first submenu carrying the parent's title, so the
        // list opens with "FundKit" under "FundKit". Naming it here replaces it.
        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'fundkit-fundraising-campaigns'),
            __('Dashboard', 'fundkit-fundraising-campaigns'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard']
        );

        $pages = apply_filters('fundkit.admin.pages', []);
        usort($pages, fn ($a, $b) => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

        foreach ($pages as $page) {
            $parent = ! empty($page['hidden']) ? null : self::SLUG;
            add_submenu_page(
                $parent,
                $page['title'] ?? '',
                $page['title'] ?? '',
                $page['capability'] ?? self::CAPABILITY,
                $page['id'] ?? '',
                $page['render'] ?? '__return_null'
            );
        }
    }

    /** @since 1.0.0 */
    private static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round">'
            . '<path d="M3 6.8 C5.4 4.5 7.6 4.5 10 6.8 S14.6 9.1 17 6.8" />'
            . '<path d="M3 13.8 C5.4 11.5 7.6 11.5 10 13.8 S14.6 16.1 17 13.8" />'
            . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Adds FundKit actions (go to donations, new campaign, ...) to the WP 7.0
     * global command palette (Cmd/Ctrl+K). Loads on every admin screen so the
     * commands are available from anywhere.
     *
     * @since 1.0.0
     */
    public function enqueueCommandPalette(): void
    {
        if (! current_user_can(self::CAPABILITY)) return;

        $assetPath = FUNDKIT_DIR . 'build/admin/command-palette/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            'fundkit-admin-command-palette',
            FUNDKIT_URL . 'build/admin/command-palette/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );
        wp_set_script_translations('fundkit-admin-command-palette', 'fundkit-fundraising-campaigns', FUNDKIT_DIR . 'languages');
        wp_localize_script('fundkit-admin-command-palette', 'fundkitCommandPalette', [
            'adminUrl' => admin_url(),
        ]);
    }

    /** @since 1.0.0 */
    public function renderDashboard(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <?php // WP moves admin notices to just after this marker. Without it they
                  // land beside the React header instead of above it. ?>
            <hr class="wp-header-end" />
            <div id="fundkit-admin-dashboard"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = FUNDKIT_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            FUNDKIT_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? FUNDKIT_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE, 'fundkit-fundraising-campaigns', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            FUNDKIT_URL . 'build/admin/dashboard.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/dashboard.css') ?: FUNDKIT_VERSION)
        );
    }
}
