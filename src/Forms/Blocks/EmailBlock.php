<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

use FundKit\Foundation\Helpers\View;

/**
 * Email input field block.
 *
 * @since 1.0.0
 */
final class EmailBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'fundkit/email';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => true],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/email', [
            'label'       => (string) ($attrs['label'] ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'required'    => (bool) ($attrs['required'] ?? true),
        ]);
    }
}
