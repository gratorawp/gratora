<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

/** @since 1.0.0 */
final class StepBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'fundkit/step';
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
     * WordPress renders and escapes child blocks before this callback; escaping $content would
     * double-escape them.
     *
     * @since 1.0.0
     */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="fundkit-block fundkit-block--step">%s</div>', $content);
    }
}
