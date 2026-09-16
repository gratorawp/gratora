<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

use Gratora\Forms\Rendering\FormMarkup;

/** @since 1.0.0 */
final class RowBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/row';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'columns' => ['type' => 'number', 'default' => 2],
            'gap'     => ['type' => 'number', 'default' => 12],
            'gapUnit' => ['type' => 'string', 'default' => 'px'],
        ];
    }

    /** @since 1.0.0 */
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

        $style = sprintf('display:grid;grid-template-columns:repeat(%d,minmax(0,1fr));gap:%d%s', $columns, $gap, $gapUnit);

        return sprintf(
            '<div class="gratora-block gratora-block--row" style="%s">%s</div>',
            esc_attr($style),
            wp_kses($content, FormMarkup::allowedHtml())
        );
    }
}
