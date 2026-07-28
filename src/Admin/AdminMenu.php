<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Registers the Dono top-level admin menu and its dynamic subpages.
 *
 * @version 1.0.0
 */
final class AdminMenu extends HookProvider
{
    private const CAPABILITY = 'dono_access';
    private const SLUG       = 'dono';
    private const HANDLE     = 'dono-admin-dashboard';
    private const BUILD_DIR  = 'build/admin/dashboard';

    protected function actions(): array
    {
        return [
            'admin_menu'            => 'registerMenu',
            'admin_enqueue_scripts' => 'enqueueCommandPalette',
        ];
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Dono', 'dono'),
            __('Dono', 'dono'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard'],
            self::menuIcon(),
            30
        );

        $pages = apply_filters('dono.admin.pages', []);
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

    private static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black" fill-rule="evenodd">'
            . '<path d="M5 1 H15 A4 4 0 0 1 19 5 V15 A4 4 0 0 1 15 19 H5 A4 4 0 0 1 1 15 V5 A4 4 0 0 1 5 1 Z'
            . ' M5 4 H10 A6 6 0 0 1 16 10 A6 6 0 0 1 10 16 H5 Z" />'
            . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Adds Dono actions (go to donations, new campaign, ...) to the WP 7.0
     * global command palette (Cmd/Ctrl+K). Loads on every admin screen so the
     * commands are available from anywhere.
     */
    public function enqueueCommandPalette(): void
    {
        if (! current_user_can(self::CAPABILITY)) return;

        $assetPath = DONO_DIR . 'build/admin/command-palette/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            'dono-admin-command-palette',
            DONO_URL . 'build/admin/command-palette/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? DONO_VERSION,
            true
        );
        wp_set_script_translations('dono-admin-command-palette', 'dono', DONO_DIR . 'languages');
        wp_localize_script('dono-admin-command-palette', 'donoCommandPalette', [
            'adminUrl' => admin_url(),
        ]);
    }

    public function renderDashboard(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <?php // WP moves admin notices to just after this marker. Without it they
                  // land beside the React header instead of above it. ?>
            <hr class="wp-header-end" />
            <div id="dono-admin-dashboard"></div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $assetPath = DONO_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

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
            self::HANDLE,
            DONO_URL . 'build/admin/dashboard.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
