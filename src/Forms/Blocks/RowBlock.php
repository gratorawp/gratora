<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Multi-column grid layout block.
 *
 * @version 1.0.0
 */
final class RowBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/row';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'columns' => ['type' => 'number', 'default' => 2],
            'gap'     => ['type' => 'number', 'default' => 12],
            'gapUnit' => ['type' => 'string', 'default' => 'px'],
        ];
    }

    /**
     * Renders the grid container.
     */
    public function render(array $attrs, string $content): string
    {
        $columns = (int) ($attrs['columns'] ?? 2);
        if ($columns < 1 || $columns > 4) $columns = 2;
        $gap = (int) ($attrs['gap'] ?? 12);
        if ($gap < 0 || $gap > 40) $gap = 12;

        $gapUnit = (string) ($attrs['gapUnit'] ?? 'px');
        if (! in_array($gapUnit, ['px', 'em', 'rem', '%'], true)) {
            $gapUnit = 'px';
        }

        return sprintf(
            '<div class="dono-block dono-block--row" style="display:grid;grid-template-columns:repeat(%d,minmax(0,1fr));gap:%d%s">%s</div>',
            $columns,
            $gap,
            $gapUnit,
            $content
        );
    }
}
