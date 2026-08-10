<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Numeric input field block.
 *
 * @since 1.0.0
 */
final class NumberInputBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/number-input';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',         'default' => ''],
            'placeholder' => ['type' => 'string',         'default' => ''],
            'helpText'    => ['type' => 'string',         'default' => ''],
            'required'    => ['type' => 'boolean',        'default' => false],
            'min'         => ['type' => ['number','null'], 'default' => null],
            'max'         => ['type' => ['number','null'], 'default' => null],
            'step'        => ['type' => 'number',         'default' => 1],
            'field'       => ['type' => 'string',         'default' => ''],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $min  = $attrs['min'] ?? null;
        $max  = $attrs['max'] ?? null;
        $step = $attrs['step'] ?? 1;
        return View::loadRelative(__DIR__, 'views/number-input', [
            'label'       => (string) ($attrs['label']       ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'helpText'    => (string) ($attrs['helpText']    ?? ''),
            'required'    => (bool)   ($attrs['required']    ?? false),
            'min'         => is_numeric($min) ? (float) $min : null,
            'max'         => is_numeric($max) ? (float) $max : null,
            'step'        => is_numeric($step) ? (float) $step : 1.0,
            'field'       => DateBlock::slugifyField((string) ($attrs['field'] ?? '')),
        ]);
    }
}
