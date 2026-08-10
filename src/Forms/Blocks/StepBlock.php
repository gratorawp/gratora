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

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="dono-block dono-block--step">%s</div>', $content);
    }
}
