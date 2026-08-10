<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Phone number field block.
 *
 * @since 1.0.0
 */
final class PhoneBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/phone';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/phone', [
            'label'       => (string) ($attrs['label'] ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'required'    => (bool) ($attrs['required'] ?? false),
        ]);
    }
}
