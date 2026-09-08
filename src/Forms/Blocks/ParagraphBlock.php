<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

use FundKit\Foundation\Helpers\View;

/** @since 1.0.0 */
final class ParagraphBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'fundkit/paragraph';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'text'  => ['type' => 'string', 'default' => ''],
            'align' => ['type' => 'string', 'default' => 'left'],
        ];
    }

    /** @since 1.0.0 */
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
