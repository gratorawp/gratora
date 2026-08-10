<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Radio button group field block.
 *
 * @since 1.0.0
 */
final class RadioBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/radio';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'     => ['type' => 'string',  'default' => ''],
            'options'   => ['type' => 'array',   'default' => [
                ['label' => 'Option one', 'value' => 'option-one', 'isDefault' => false],
            ]],
            'required'  => ['type' => 'boolean', 'default' => false],
            'field'     => ['type' => 'string',  'default' => ''],
            'layout'    => ['type' => 'string',  'default' => 'vertical'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $options = DropdownBlock::normalizeOptions($attrs['options'] ?? null);
        $field   = DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? ''));
        $layout  = (string) ($attrs['layout'] ?? 'vertical');
        if (! in_array($layout, ['vertical', 'horizontal'], true)) $layout = 'vertical';

        $default = '';
        foreach ($options as $o) {
            if (! empty($o['isDefault'])) { $default = $o['value']; break; }
        }

        return View::loadRelative(__DIR__, 'views/radio', [
            'label'        => (string) ($attrs['label'] ?? ''),
            'options'      => $options,
            'required'     => (bool) ($attrs['required'] ?? false),
            'field'        => $field,
            'layout'       => $layout,
            'defaultValue' => $default,
        ]);
    }
}
