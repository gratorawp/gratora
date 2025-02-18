<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Multi-step wizard container block.
 *
 * @version 1.0.0
 */
final class StepsBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/steps';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'prevLabel'     => ['type' => 'string',  'default' => ''],
            'nextLabel'     => ['type' => 'string',  'default' => ''],
            'progressStyle' => ['type' => 'string',  'default' => 'dots'],
        ];
    }

    /**
     * Renders the steps wrapper.
     */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="dono-block dono-block--steps">%s</div>', $content);
    }
}
