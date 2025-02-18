<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Single wizard step block, contained by dono/steps.
 *
 * @version 1.0.0
 */
final class StepBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/step';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'title'     => ['type' => 'string',  'default' => ''],
            'showTitle' => ['type' => 'boolean', 'default' => true],
        ];
    }

    /**
     * Renders the step wrapper.
     */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="dono-block dono-block--step">%s</div>', $content);
    }
}
