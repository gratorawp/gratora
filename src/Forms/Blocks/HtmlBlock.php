<?php

declare(strict_types=1);

namespace GiveFlow\Forms\Blocks;

/**
 * giveflow/html: inline HTML decoration.
 *
 * @since 1.0.0
 */
final class HtmlBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'giveflow/html';
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
        return sprintf('<div class="giveflow-block giveflow-block--html">%s</div>', self::sanitize($raw));
    }

    /**
     * Strip scripts, event handlers, and javascript: URLs via wp_kses_post.
     *
     * @since 1.0.0
     */
    public static function sanitize(string $raw): string
    {
        return wp_kses_post($raw);
    }
}
