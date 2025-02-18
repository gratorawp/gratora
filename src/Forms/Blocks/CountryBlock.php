<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Country select field block.
 *
 * @version 1.0.0
 */
final class CountryBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/country';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/country', [
            'label'       => (string) ($attrs['label'] ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'required'    => (bool) ($attrs['required'] ?? false),
        ]);
    }
}
