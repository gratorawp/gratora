<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

/** @since 1.0.0 */
final class StepsBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'fundkit/steps';
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

    /**
     * WordPress renders and escapes child blocks before this callback; escaping $content would
     * double-escape them.
     *
     * @since 1.0.0
     */
    public function render(array $attrs, string $content): string
    {
        return sprintf('<div class="fundkit-block fundkit-block--steps">%s</div>', $content);
    }
}
