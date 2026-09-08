<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

/** @since 1.0.0 */
final class HiddenBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'fundkit/hidden';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'field'        => ['type' => 'string', 'default' => ''],
            'source'       => ['type' => 'string', 'default' => 'fixed'],
            'queryParam'   => ['type' => 'string', 'default' => ''],
            'defaultValue' => ['type' => 'string', 'default' => ''],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return '';
    }
}
