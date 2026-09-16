<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

/** @since 1.0.0 */
final class HtmlBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/html';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'content' => ['type' => 'string', 'default' => ''],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $raw = (string) ($attrs['content'] ?? '');
        if ($raw === '') return '';
        return sprintf('<div class="gratora-block gratora-block--html">%s</div>', wp_kses_post($raw));
    }

    /** @since 1.0.0 */
    public static function sanitize(string $raw): string
    {
        return wp_kses_post($raw);
    }
}
