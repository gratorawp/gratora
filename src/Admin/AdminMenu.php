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
            __('Fundraising Toolkit', 'fundraising-toolkit'),
            // The sidebar label is the one word that has to survive a narrow
            // menu; the full name still titles the page it opens.
            __('Fundraising', 'fundraising-toolkit'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderDashboard'],
            self::menuIcon(),
            30
        );

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
        // Shapes rather than type, because WordPress inlines this as a base64
        // data URI where there is no font to fall back on. Filled rather than
        // stroked, because svg-painter.js sets fill on the root element to
        // match the admin colour scheme: an open stroked path picks that up and
        // fills into a blob. So the cupped hand is a closed crescent, drawn out
        // along one arc and back along a tighter one.
        //
        // Two hands raised around a coin. The second hand is a mirror transform
        // rather than a second path: hand-reversing the arc sweep flags is how
        // the first attempt at this ended up drawing a spoon. Sized to fill the
        // 20px box so it carries the same optical weight as the core icons
        // above and below it.
        $hand = 'M2.6 10.6a1.35 1.35 0 0 1 2.7 0v3.9a5.9 5.9 0 0 0 4.3 5.7l1.5.4v2.75l-2.2-.6'
            . 'A8.6 8.6 0 0 1 2.6 14.5z';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ffffff" stroke="none">'
            . '<circle cx="12" cy="7.4" r="4.3" />'
            . '<path d="' . $hand . '" />'
            . '<g transform="translate(24 0) scale(-1 1)"><path d="' . $hand . '" /></g>'
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
        wp_set_script_translations('fundkit-admin-command-palette', 'fundraising-toolkit', FUNDKIT_DIR . 'languages');
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
        wp_set_script_translations(self::HANDLE, 'fundraising-toolkit', FUNDKIT_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            FUNDKIT_URL . 'build/admin/dashboard.css',
            ['wp-components'],
            (string) (@filemtime(FUNDKIT_DIR . 'build/admin/dashboard.css') ?: FUNDKIT_VERSION)
        );
    }
}
