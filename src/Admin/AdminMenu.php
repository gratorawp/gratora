<?php

declare(strict_types=1);

namespace GiveFlow\Admin;

use GiveFlow\Foundation\Hooks\HookProvider;

/**
 * Registers the GiveFlow top-level admin menu and its dynamic subpages.
 *
 * @since 1.0.0
 */
final class AdminMenu extends HookProvider
{
    private const CAPABILITY = 'giveflow_access';
    private const SLUG       = 'giveflow';
    private const HANDLE     = 'giveflow-admin-dashboard';
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
            __('GiveFlow', 'giveflow-fundraising-campaigns'),
            __('GiveFlow', 'giveflow-fundraising-campaigns'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard'],
            self::menuIcon(),
            30
        );

        $pages = apply_filters('giveflow.admin.pages', []);
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
     * Adds GiveFlow actions (go to donations, new campaign, ...) to the WP 7.0
     * global command palette (Cmd/Ctrl+K). Loads on every admin screen so the
     * commands are available from anywhere.
     *
     * @since 1.0.0
     */
    public function enqueueCommandPalette(): void
    {
        if (! current_user_can(self::CAPABILITY)) return;

        $assetPath = GIVEFLOW_DIR . 'build/admin/command-palette/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            'giveflow-admin-command-palette',
            GIVEFLOW_URL . 'build/admin/command-palette/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );
        wp_set_script_translations('giveflow-admin-command-palette', 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');
        wp_localize_script('giveflow-admin-command-palette', 'giveflowCommandPalette', [
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
            <div id="giveflow-admin-dashboard"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = GIVEFLOW_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            GIVEFLOW_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GIVEFLOW_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE, 'giveflow-fundraising-campaigns', GIVEFLOW_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            GIVEFLOW_URL . 'build/admin/dashboard.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/dashboard.css') ?: GIVEFLOW_VERSION)
        );
    }
}
