<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

/** @since 1.0.0 */
final class StepBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/step';
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
        return sprintf('<div class="gratora-block gratora-block--step">%s</div>', $content);
    }
}
