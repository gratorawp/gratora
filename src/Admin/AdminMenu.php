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

    /**
     * The brand mark, inline.
     *
     * WordPress paints a base64 SVG as the menu item's background rather than
     * an <img>, so it carries its own colour instead of taking the menu's, and
     * it is sized `20px auto`. Declaring width and height gives the image an
     * intrinsic size that `auto` resolves to outside Chromium, painting a
     * 20-by-256 slice of the middle: viewBox only. The box is cropped to the
     * glyph so it reads at 20 pixels beside the dashicons.
     *
     * @since 1.0.0
     */
    private static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="103.4 103.4 811 811">'
            . '<path fill="#fff" d="M491.1 852.7Q406.8 852.7 345 810.7Q283.3 768.7 249.9 692.4Q216.5 616 216.5 513.9Q216.5 438.7 235.3 375.1Q254.1 311.5 292.9 264.4Q331.6 217.2 390.5 191.1Q449.3 165 529.5 165Q595.1 165 644 182.9Q692.8 200.7 725 233.2Q757.3 265.7 771.7 309.6Q786.1 353.5 783 405.9L647.3 422.7Q648.5 376 633.7 346Q619 315.9 591 300.9Q563.1 285.9 524.1 285.9Q478.3 285.9 443 310.3Q407.6 334.7 387.7 383.8Q367.8 432.9 367.8 507.2Q367.8 562.7 379.4 605.5Q391 648.2 413.1 676.7Q435.3 705.2 467 719.8Q498.6 734.4 537.8 734.4Q580 734.4 609.3 718.9Q638.6 703.4 654.5 675.5Q670.5 647.7 671.5 610H561.5V505.7H801V624.9L801.3 838.9H699.1L702.1 658.2H693.1Q687.1 721 661.8 764.5Q636.5 808 593.5 830.4Q550.5 852.7 491.1 852.7Z"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
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
            self::menuIcon(),
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
