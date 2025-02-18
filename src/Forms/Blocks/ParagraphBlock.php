<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Static paragraph text block.
 *
 * @version 1.0.0
 */
final class ParagraphBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/paragraph';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'text'  => ['type' => 'string', 'default' => ''],
            'align' => ['type' => 'string', 'default' => 'left'],
        ];
    }

    /**
     * Renders the paragraph element.
     */
    public function render(array $attrs, string $content): string
    {
        $align = (string) ($attrs['align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }

        return View::loadRelative(__DIR__, 'views/paragraph', [
            'text'  => (string) ($attrs['text'] ?? ''),
            'align' => $align,
        ]);
    }
}
