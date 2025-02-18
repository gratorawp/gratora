<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Heading text block.
 *
 * @version 1.0.0
 */
final class HeadingBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/heading';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'text'  => ['type' => 'string', 'default' => ''],
            'level' => ['type' => 'number', 'default' => 2],
            'align' => ['type' => 'string', 'default' => 'left'],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $level = (int) ($attrs['level'] ?? 2);
        if ($level < 1 || $level > 6) $level = 2;

        $align = (string) ($attrs['align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }

        return View::loadRelative(__DIR__, 'views/heading', [
            'text'  => (string) ($attrs['text'] ?? ''),
            'level' => $level,
            'align' => $align,
        ]);
    }
}
