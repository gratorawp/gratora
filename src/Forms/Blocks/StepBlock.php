<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Single wizard step block, contained by dono/steps.
 *
 * @since 1.0.0
 */
final class StepBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/step';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'title'     => ['type' => 'string',  'default' => ''],
            'showTitle' => ['type' => 'boolean', 'default' => true],
        ];
    }

    /**
     * $content is the inner blocks' markup, rendered by WordPress before this
     * callback runs, exactly as core's own container blocks receive it.
     * Escaping it here would double-escape every child; each child escapes its
     * own values at their interpolation points.
     *
     * @since 1.0.0
     */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="dono-block dono-block--step">%s</div>', $content);
    }
}
