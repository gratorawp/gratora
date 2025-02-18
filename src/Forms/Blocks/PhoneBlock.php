<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Phone number field block.
 *
 * @version 1.0.0
 */
final class PhoneBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/phone';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => false],
        ];
    }

    /**
     * Renders the phone input field.
     */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/phone', [
            'label'       => (string) ($attrs['label'] ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'required'    => (bool) ($attrs['required'] ?? false),
        ]);
    }
}
