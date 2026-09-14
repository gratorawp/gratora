<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class AdminMenu extends HookProvider
{
    private const CAPABILITY = 'gratora_access';
    private const SLUG       = 'gratora';
    private const HANDLE     = 'gratora-admin-dashboard';
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
        $hook = add_menu_page(
            __('Gratora', 'gratora-donation-platform'),
            __('Fundraising', 'gratora-donation-platform'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard'],
            'dashicons-heart',
            30
        );

        // Redirect on load- while headers are still open.
        if ($hook) {
            add_action("load-{$hook}", [$this, 'redirectToFirstReachablePage']);
        }

        // Replace WordPress’s duplicate parent submenu label.
        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'gratora-donation-platform'),
            __('Dashboard', 'gratora-donation-platform'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard']
        );

        foreach ($this->pages() as $page) {
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

        // Remove the default submenu after registration so WordPress cannot recreate it under
        // the parent capability.
        if (! current_user_can('gratora_access_reports')) {
            remove_submenu_page(self::SLUG, self::SLUG);
        }
    }

    /**
     * The subpages add-ons and core register, in the order they are shown.
     *
     * @return list<array<string, mixed>>
     *
     * @since 1.0.0
     */
    private function pages(): array
    {
        $pages = apply_filters('gratora.admin.pages', []);
        usort($pages, fn ($a, $b) => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

        return $pages;
    }

    /**
     * Redirect unauthorized dashboard URLs to the first accessible page.
     *
     * @since 1.0.0
     */
    public function redirectToFirstReachablePage(): void
    {
        if (current_user_can('gratora_access_reports')) {
            return;
        }

        foreach ($this->pages() as $page) {
            $cap = (string) ($page['capability'] ?? self::CAPABILITY);
            $id  = (string) ($page['id'] ?? '');
            if ($id === '' || ! empty($page['hidden']) || ! current_user_can($cap)) {
                continue;
            }

            wp_safe_redirect(admin_url('admin.php?page=' . rawurlencode($id)));
            exit;
        }
    }

    /**
     * Adds Gratora actions (go to donations, new campaign, ...) to the WP 7.0
     * global command palette (Cmd/Ctrl+K). Loads on every admin screen so the
     * commands are available from anywhere.
     *
     * @since 1.0.0
     */
    public function enqueueCommandPalette(): void
    {
        if (! current_user_can(self::CAPABILITY)) return;

        $assetPath = GRATORA_DIR . 'build/admin/command-palette/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            'gratora-admin-command-palette',
            GRATORA_URL . 'build/admin/command-palette/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GRATORA_VERSION,
            true
        );
        wp_set_script_translations('gratora-admin-command-palette', 'gratora-donation-platform', GRATORA_DIR . 'languages');
        // Check each destination separately; the umbrella capability grants only menu access.
        $can = [self::SLUG => true];
        foreach ($this->pages() as $page) {
            $id = (string) ($page['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $can[$id] = current_user_can((string) ($page['capability'] ?? self::CAPABILITY));
        }

        wp_localize_script('gratora-admin-command-palette', 'gratoraCommandPalette', [
            'adminUrl' => admin_url(),
            'can'      => $can,
        ]);
    }

    /** @since 1.0.0 */
    public function renderDashboard(): void
    {
        $this->enqueueAssets();
        ?>
        <div class="wrap">
            <?php // Keep WordPress notices above the React header.?>
            <hr class="wp-header-end" />
            <div id="gratora-admin-dashboard"></div>
        </div>
        <?php
    }

    /** @since 1.0.0 */
    private function enqueueAssets(): void
    {
        $assetPath = GRATORA_DIR . self::BUILD_DIR . '/index.asset.php';
        if (! file_exists($assetPath)) return;

        $asset = require $assetPath;

        wp_enqueue_script(
            self::HANDLE,
            GRATORA_URL . self::BUILD_DIR . '/index.js',
            $asset['dependencies'] ?? [],
            $asset['version']      ?? GRATORA_VERSION,
            true
        );
        wp_set_script_translations(self::HANDLE, 'gratora-donation-platform', GRATORA_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            GRATORA_URL . 'build/admin/dashboard.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/dashboard.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
