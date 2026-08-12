<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Inline privacy notice. Link omitted when no privacy policy URL is set.
 *
 * @since 1.0.0
 */
final class PrivacyNoticeBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/privacy-notice';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            // Empty defaults so an unedited block falls back to the translated
            // strings in render() rather than shipping fixed English.
            'text'     => ['type' => 'string', 'default' => ''],
            'linkText' => ['type' => 'string', 'default' => ''],
            'align'    => ['type' => 'string', 'default' => 'left'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $align = (string) ($attrs['align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }

        $privacy = get_option('dono_privacy', []);
        $url = is_array($privacy) ? trim((string) ($privacy['privacy_policy_url'] ?? '')) : '';

        $text     = trim((string) ($attrs['text']     ?? ''));
        $linkText = trim((string) ($attrs['linkText'] ?? ''));

        return View::loadRelative(__DIR__, 'views/privacy-notice', [
            'text'     => $text     !== '' ? $text     : __('By donating you agree to our', 'dono-fundraising-platform'),
            'linkText' => $linkText !== '' ? $linkText : __('Privacy Policy', 'dono-fundraising-platform'),
            'align'    => $align,
            'url'      => $url,
        ]);
    }
}
