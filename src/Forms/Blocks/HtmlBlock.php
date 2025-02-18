<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * dono/html: inline HTML decoration.
 *
 * @version 1.0.0
 */
final class HtmlBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/html';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'content' => ['type' => 'string', 'default' => ''],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $raw = (string) ($attrs['content'] ?? '');
        if ($raw === '') return '';
        return sprintf('<div class="dono-block dono-block--html">%s</div>', self::sanitize($raw));
    }

    /** Strip scripts, event handlers, and javascript: URLs via wp_kses_post. */
    public static function sanitize(string $raw): string
    {
        return wp_kses_post($raw);
    }
}
