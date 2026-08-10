<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * dono/columns: multi-column container for content blocks.
 *
 * @since 1.0.0
 */
final class ColumnsBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/columns';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'columns' => ['type' => 'number', 'default' => 2],
            'gap'     => ['type' => 'number', 'default' => 16],
            'gapUnit' => ['type' => 'string', 'default' => 'px'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return sprintf(
            '<div class="dono-block dono-block--columns" style="%s">%s</div>',
            esc_attr(self::columnsStyle($attrs)),
            $content
        );
    }

    /**
     * @param array<string,mixed> $attrs
     *
     * @since 1.0.0
     */
    public static function columnsStyle(array $attrs): string
    {
        $columns = (int) ($attrs['columns'] ?? 2);
        if ($columns < 1 || $columns > 6) $columns = 2;

        $gap = (int) ($attrs['gap'] ?? 16);
        if ($gap < 0 || $gap > 80) $gap = 16;

        $gapUnit = (string) ($attrs['gapUnit'] ?? 'px');
        if (! in_array($gapUnit, ['px', 'em', 'rem', '%'], true)) {
            $gapUnit = 'px';
        }

        return sprintf(
            'display:grid;grid-template-columns:repeat(%d,minmax(0,1fr));gap:%d%s',
            $columns,
            $gap,
            $gapUnit
        );
    }
}
