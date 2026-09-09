<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Gratora\Foundation\Hooks\HookProvider;

/** @since 1.0.0 */
final class AdminFooter extends HookProvider
{
    /**
     * Keep this slug in sync with the text domain, package folder, and directory permalink.
     *
     * @since 1.0.0
     */
    private const SLUG = 'gratora';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return ['admin_footer_text' => 'reviewPrompt'];
    }

    /** @since 1.0.0 */
    public function reviewPrompt(string $text): string
    {
        if (! $this->isGratoraAdminPage()) return $text;

        $stars = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" style="text-decoration:none;">%s</a>',
            esc_url($this->reviewUrl()),
            esc_attr__('Leave a five star review on WordPress.org', 'gratora'),
            str_repeat(
                '<span class="dashicons dashicons-star-filled" style="font-size:15px;width:15px;height:15px;vertical-align:text-bottom;"></span>',
                5
            )
        );

        return sprintf(
            /* translators: 1: plugin name, 2: five star icons linking to the review form */
            esc_html__('If you like %1$s please leave us a %2$s rating. Thanks in advance!', 'gratora'),
            '<strong>' . esc_html__('Gratora', 'gratora') . '</strong>',
            $stars
        );
    }

    /** @since 1.0.0 */
    private function reviewUrl(): string
    {
        return 'https://wordpress.org/support/plugin/' . self::SLUG . '/reviews/?rate=5#new-post';
    }

    /** @since 1.0.0 */
    private function isGratoraAdminPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'gratora' || strpos($page, 'gratora-') === 0;
    }
}
