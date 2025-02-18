<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * dono/hidden: invisible value captured with the donation.
 *
 * @version 1.0.0
 */
final class HiddenBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/hidden';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'field'        => ['type' => 'string', 'default' => ''],
            'source'       => ['type' => 'string', 'default' => 'fixed'],
            'queryParam'   => ['type' => 'string', 'default' => ''],
            'defaultValue' => ['type' => 'string', 'default' => ''],
        ];
    }

    /** Hidden block renders no visible markup. */
    public function render(array $attrs, string $content): string
    {
        return '';
    }
}
