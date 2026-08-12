<?php

declare(strict_types=1);

namespace Dono\Onboarding;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Full-screen first-run onboarding page (hidden submenu, no WP chrome).
 *
 * @since 1.0.0
 */
final class OnboardingPage extends HookProvider
{
    public const PAGE_ID   = 'dono-onboarding';
    private const HANDLE   = 'dono-admin-onboarding';
    private const BUILD_DIR = 'build/admin/onboarding';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            'dono.admin.pages' => 'registerPage',
            'admin_body_class' => 'maybeAddBodyClass',
        ];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Onboarding', 'dono'),
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
            $classes .= ' dono-onboarding-fullscreen';
        }
        return $classes;
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div id="dono-admin-onboarding"></div>
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
            DONO_URL . 'build/admin/onboarding.css',
            ['wp-components'],
            $asset['version'] ?? DONO_VERSION
        );
    }
}
