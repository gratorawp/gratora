<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Multi-step wizard container block.
 *
 * @since 1.0.0
 */
final class StepsBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/steps';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'prevLabel'     => ['type' => 'string',  'default' => ''],
            'nextLabel'     => ['type' => 'string',  'default' => ''],
            'progressStyle' => ['type' => 'string',  'default' => 'dots'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="dono-block dono-block--steps">%s</div>', $content);
    }
}
