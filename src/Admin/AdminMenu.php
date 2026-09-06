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
        $hook = add_menu_page(
            __('Fundraising Toolkit', 'fundraising-toolkit'),
            // The sidebar label is the one word that has to survive a narrow
            // menu; the full name still titles the page it opens.
            __('Fundraising', 'fundraising-toolkit'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard'],
            'dashicons-heart',
            30
        );

        // load- fires before the admin header, so headers are still open; the
        // render callback would be too late to forward.
        if ($hook) {
            add_action("load-{$hook}", [$this, 'redirectToFirstReachablePage']);
        }

        // add_menu_page mints a first submenu carrying the parent's title, so the
        // list opens with "Fundraising" under "Fundraising". Naming it here replaces it.
        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'fundraising-toolkit'),
            __('Dashboard', 'fundraising-toolkit'),
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

        // Registered and then withdrawn, rather than declared on the reports
        // cap: a parent with no submenu of its own has core mint one under the
        // parent's capability the moment the next page is added, which puts the
        // dashboard back for a reader who cannot hold its data. WP then links
        // the top-level item at the first surviving submenu, so a role scoped
        // to one area lands on that area.
        if (! current_user_can('fundkit_access_reports')) {
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
        $pages = apply_filters('fundkit.admin.pages', []);
        usort($pages, fn ($a, $b) => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

        return $pages;
    }

    /**
     * A bookmark or a typed URL reaches the dashboard past the menu, so a
     * reader who cannot hold its data is forwarded to the first page they can
     * open rather than left on an empty screen retrying a refused request.
     *
     * @since 1.0.0
     */
    public function redirectToFirstReachablePage(): void
    {
        if (current_user_can('fundkit_access_reports')) {
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
        wp_set_script_translations('fundkit-admin-command-palette', 'fundraising-toolkit', FUNDKIT_DIR . 'languages');
        // The palette is enqueued on the umbrella cap, which any single area
        // cap grants, so every destination has to be asked for separately.
        $can = [self::SLUG => true];
        foreach ($this->pages() as $page) {
            $id = (string) ($page['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $can[$id] = current_user_can((string) ($page['capability'] ?? self::CAPABILITY));
        }

        wp_localize_script('fundkit-admin-command-palette', 'fundkitCommandPalette', [
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
        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            FUNDKIT_URL . 'build/admin/dashboard.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/dashboard.css') ?: FUNDKIT_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
