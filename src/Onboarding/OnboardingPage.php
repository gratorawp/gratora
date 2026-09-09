<?php

declare(strict_types=1);

namespace Gratora\Onboarding;

use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class OnboardingPage extends HookProvider
{
    public const PAGE_ID   = 'gratora-onboarding';
    private const HANDLE   = 'gratora-admin-onboarding';
    private const BUILD_DIR = 'build/admin/onboarding';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            'gratora.admin.pages' => 'registerPage',
            'admin_body_class' => 'maybeAddBodyClass',
        ];
    }

    /** @since 1.0.0 */
    public function registerPage(array $pages): array
    {
        $pages[] = [
            'id'         => self::PAGE_ID,
            'title'      => __('Onboarding', 'gratora'),
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
            $classes .= ' gratora-onboarding-fullscreen';
        }
        return $classes;
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        $this->enqueueAssets();
        ?>
        <div id="gratora-admin-onboarding"></div>
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
        wp_set_script_translations(self::HANDLE, 'gratora', GRATORA_DIR . 'languages');

        wp_enqueue_style('wp-components');
        wp_enqueue_style(
            self::HANDLE,
            GRATORA_URL . 'build/admin/onboarding.css',
            ['wp-components'],
            (string) (@filemtime(GRATORA_DIR . 'build/admin/onboarding.css') ?: GRATORA_VERSION)
        );
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
    }
}
