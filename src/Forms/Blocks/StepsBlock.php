<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

/** @since 1.0.0 */
final class StepsBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/steps';
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
        return sprintf('<div class="gratora-block gratora-block--steps">%s</div>', $content);
    }
}
