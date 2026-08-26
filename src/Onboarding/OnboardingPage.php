<?php

declare(strict_types=1);

namespace GiveFlow\Onboarding;

use GiveFlow\Foundation\Hooks\HookProvider;

/**
 * Full-screen first-run onboarding page (hidden submenu, no WP chrome).
 *
 * @since 1.0.0
 */
final class OnboardingPage extends HookProvider
{
    public const PAGE_ID   = 'giveflow-onboarding';
    private const HANDLE   = 'giveflow-admin-onboarding';
    private const BUILD_DIR = 'build/admin/onboarding';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            'giveflow.admin.pages' => 'registerPage',
            'admin_body_class' => 'maybeAddBodyClass',
        ];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Onboarding', 'giveflow-fundraising-campaigns'),
            'capability' => 'manage_options',
            'position'   => 999,
            'hidden'     => true,
            'render'     => [$this, 'render'],
        ];
        return $pages;
    }

    /** @since 1.0.0 */
    public function maybeAddBodyClass(string $classes): string
    {
        if ($this->isCurrentPage()) {
            $classes .= ' giveflow-onboarding-fullscreen';
        }
        return $classes;
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div id="giveflow-admin-onboarding"></div>
        <?php
    }

    /** @since 1.0.0 */
    private function isCurrentPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === self::PAGE_ID;
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
            GIVEFLOW_URL . 'build/admin/onboarding.css',
            ['wp-components'],
            (string) (@filemtime(GIVEFLOW_DIR . 'build/admin/onboarding.css') ?: GIVEFLOW_VERSION)
        );
    }
}
