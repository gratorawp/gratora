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
        // lucide's heart-plus, redrawn as filled shapes.
        //
        // It cannot be used as lucide ships it. svg-painter.js recolours this
        // icon by rewriting every fill="..." in the markup to the admin scheme
        // colour (wp-admin/js/svg-painter.js), so lucide's fill="none" becomes
        // a colour and the heart fills into a blob. It never touches stroke,
        // and the icon is repainted as a background image rather than inlined,
        // so stroke="currentColor" has nothing to inherit from and resolves to
        // black. Painting therefore has to happen through fill, which means the
        // outline is a ring: the heart drawn twice, once inset, with evenodd
        // clearing the middle. fill-rule survives the rewrite because the
        // pattern matches fill=" and not fill-rule=".
        //
        // The plus sits clear of the lower-right lobe rather than over it as in
        // lucide, which interrupts its heart stroke to make room; two filled
        // shapes cannot interrupt each other, and overlapped they read as a
        // Venus symbol at 20px. Its round caps are why each 6-long arm of
        // stroke-width 2 becomes a 7.3 x 1.9 rounded rect.
        $outer = 'M12 20.7l-1.45-1.32C5.4 14.36 2 11.28 2 7.5 2 4.42 4.42 2 7.5 2'
            . 'c1.74 0 3.41.81 4.5 2.09C13.09 2.81 14.76 2 16.5 2 19.58 2 22 4.42 22 7.5'
            . 'c0 3.78-3.4 6.86-8.55 11.54L12 20.7z';
        $inner = 'M12 18.68l-1.13-1.03C6.85 13.73 4.2 11.33 4.2 8.38 4.2 5.98 6.09 4.09 8.49 4.09'
            . 'c1.36 0 2.66.63 3.51 1.63C12.85 4.72 14.15 4.09 15.51 4.09 17.91 4.09 19.8 5.98 19.8 8.38'
            . 'c0 2.95-2.65 5.35-6.67 9L12 18.68z';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ffffff" stroke="none">'
            . '<g transform="translate(-1.6 -1.4) scale(0.9)">'
            . '<path fill-rule="evenodd" d="' . $outer . ' ' . $inner . '" />'
            . '</g>'
            . '<rect x="14.95" y="16.45" width="7.3" height="1.9" rx="0.95" />'
            . '<rect x="17.65" y="13.75" width="1.9" height="7.3" rx="0.95" />'
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
