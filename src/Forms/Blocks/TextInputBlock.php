<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Single-line text input field block.
 *
 * @since 1.0.0
 */
final class TextInputBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/text-input';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'helpText'    => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => false],
            'maxLength'   => ['type' => 'integer', 'default' => 0],
            'pattern'     => ['type' => 'string',  'default' => ''],
            'field'       => ['type' => 'string',  'default' => ''],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/text-input', [
            'label'       => (string) ($attrs['label']       ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'helpText'    => (string) ($attrs['helpText']    ?? ''),
            'required'    => (bool)   ($attrs['required']    ?? false),
            'maxLength'   => max(0, (int) ($attrs['maxLength'] ?? 0)),
            'pattern'     => (string) ($attrs['pattern']     ?? ''),
            'field'       => DateBlock::slugifyField((string) ($attrs['field'] ?? '')),
        ]);
    }
}
