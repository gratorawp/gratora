<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Multi-select field block.
 *
 * @version 1.0.0
 */
final class MultiSelectBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/multi-select';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'label'         => ['type' => 'string',  'default' => ''],
            'options'       => ['type' => 'array',   'default' => [
                ['label' => 'Option one', 'value' => 'option-one', 'isDefault' => false],
            ]],
            'required'      => ['type' => 'boolean', 'default' => false],
            'field'         => ['type' => 'string',  'default' => ''],
            'minSelections' => ['type' => 'number',  'default' => 0],
            'maxSelections' => ['type' => 'number',  'default' => 0],
        ];
    }

    /**
     * Renders the multi-select field.
     */
    public function render(array $attrs, string $content): string
    {
        $options = DropdownBlock::normalizeOptions($attrs['options'] ?? null);
        $field   = DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? ''));
        $min     = max(0, (int) ($attrs['minSelections'] ?? 0));
        $max     = max(0, (int) ($attrs['maxSelections'] ?? 0));

        return View::loadRelative(__DIR__, 'views/multi-select', [
            'label'    => (string) ($attrs['label'] ?? ''),
            'options'  => $options,
            'required' => (bool) ($attrs['required'] ?? false),
            'field'    => $field,
            'min'      => $min,
            'max'      => $max,
        ]);
    }
}
