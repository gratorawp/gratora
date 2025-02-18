<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Email input field block.
 *
 * @version 1.0.0
 */
final class EmailBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/email';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => true],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/email', [
            'label'       => (string) ($attrs['label'] ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'required'    => (bool) ($attrs['required'] ?? true),
        ]);
    }
}
