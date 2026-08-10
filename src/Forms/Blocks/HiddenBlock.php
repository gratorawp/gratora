<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * dono/hidden: invisible value captured with the donation.
 *
 * @since 1.0.0
 */
final class HiddenBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/hidden';
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

    /**
     * Hidden block renders no visible markup.
     *
     * @since 1.0.0
     */
    public function render(array $attrs, string $content): string
    {
        return '';
    }
}
