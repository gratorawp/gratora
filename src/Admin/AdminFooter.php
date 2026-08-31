<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Foundation\Hooks\HookProvider;

/**
 * Replaces the admin footer text on FundKit screens with a review prompt.
 *
 * @since 1.0.0
 */
final class AdminFooter extends HookProvider
{
    /**
     * The directory permalink, which is also the text domain and the folder
     * the packaged zip installs to. Change all four together or the review
     * link points at a plugin page that does not exist.
     *
     * @since 1.0.0
     */
    private const SLUG = 'fundkit-fundraising-campaigns';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['admin_footer_text' => 'reviewPrompt'];
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
            /* translators: 1: plugin name, 2: five star icons linking to the review form */
            esc_html__('If you like %1$s please leave us a %2$s rating. Thanks in advance!', 'fundkit-fundraising-campaigns'),
            '<strong>' . esc_html__('FundKit', 'fundkit-fundraising-campaigns') . '</strong>',
            $stars
        );
    }

    /** @since 1.0.0 */
    private function reviewUrl(): string
    {
        return 'https://wordpress.org/support/plugin/' . self::SLUG . '/reviews/?rate=5#new-post';
    }

    /** @since 1.0.0 */
    private function isFundKitAdminPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'fundkit' || strpos($page, 'fundkit-') === 0;
    }
}
