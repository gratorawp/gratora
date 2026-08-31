<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Hooks\HookProvider;

/**
 * Replaces the admin footer on FundKit screens with a review prompt and the
 * plugin version.
 *
 * @since 1.0.0
 */
final class AdminFooter extends HookProvider
{
    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            'admin_footer_text' => 'reviewPrompt',
            // Core sets update_footer at 10; a later priority is the only way
            // to take the right-hand slot from it.
            'update_footer'     => ['version', 11],
        ];
    }

    /** @since 1.0.0 */
    public function reviewPrompt(string $text): string
    {
        if (! $this->isFundKitAdminPage()) return $text;

        $stars = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" style="text-decoration:none;">%s</a>',
            esc_url($this->reviewUrl()),
            esc_attr__('Leave a five star review on WordPress.org', 'fundkit-fundraising-campaigns'),
            str_repeat(
                '<span class="dashicons dashicons-star-filled" style="font-size:15px;width:15px;height:15px;vertical-align:text-bottom;"></span>',
                5
            )
        );

        return sprintf(
            /* translators: 1: plugin name, 2: five star icons linking to the review form, 3: link to the plugin page on WordPress.org */
            esc_html__('Thank you for raising with %1$s. A %2$s review on %3$s helps other nonprofits find it.', 'fundkit-fundraising-campaigns'),
            '<strong>' . esc_html__('FundKit', 'fundkit-fundraising-campaigns') . '</strong>',
            $stars,
            sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">WordPress.org</a>',
                esc_url($this->pluginUrl())
            )
        );
    }

    /** @since 1.0.0 */
    public function version(string $text): string
    {
        if (! $this->isFundKitAdminPage()) return $text;

        return sprintf(
            /* translators: %s: plugin version number */
            esc_html__('FundKit %s', 'fundkit-fundraising-campaigns'),
            esc_html(FUNDKIT_VERSION)
        );
    }

    /**
     * The directory the plugin is installed into is the slug the directory
     * serves it under, so the links follow a permalink change without an edit.
     *
     * Read off the path rather than through plugin_basename(), which returns
     * the whole absolute path when the plugin is not inside the registered
     * plugin directory, symlinked checkouts included.
     *
     * @since 1.0.0
     */
    private function slug(): string
    {
        return basename(dirname(FUNDKIT_FILE));
    }

    /** @since 1.0.0 */
    private function pluginUrl(): string
    {
        return 'https://wordpress.org/plugins/' . $this->slug() . '/';
    }

    /** @since 1.0.0 */
    private function reviewUrl(): string
    {
        return 'https://wordpress.org/support/plugin/' . $this->slug() . '/reviews/?rate=5#new-post';
    }

    /** @since 1.0.0 */
    private function isFundKitAdminPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'fundkit' || strpos($page, 'fundkit-') === 0;
    }
}
