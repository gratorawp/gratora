<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * dono/divider: a horizontal rule with author-set spacing and line colour.
 *
 * @version 1.0.0
 */
final class DividerBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/divider';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'marginTop'    => ['type' => 'number', 'default' => 16],
            'marginBottom' => ['type' => 'number', 'default' => 16],
            'thickness'    => ['type' => 'number', 'default' => 1],
            // Empty = inherit the form border token (--dono-border).
            'color'        => ['type' => 'string', 'default' => ''],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/divider', self::settings($attrs));
    }

    /**
     * Coerced and clamped presentation values.
     *
     * @param array<string,mixed> $attrs
     * @return array{marginTop:int,marginBottom:int,thickness:int,color:string}
     */
    public static function settings(array $attrs): array
    {
        $color = is_string($attrs['color'] ?? null)
            ? (string) (sanitize_hex_color((string) $attrs['color']) ?? '')
            : '';

        return [
            'marginTop'    => max(0, min(160, (int) ($attrs['marginTop']    ?? 16))),
            'marginBottom' => max(0, min(160, (int) ($attrs['marginBottom'] ?? 16))),
            'thickness'    => max(1, min(8,   (int) ($attrs['thickness']    ?? 1))),
            'color'        => $color,
        ];
    }
}
