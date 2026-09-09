<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

use Gratora\Foundation\Helpers\View;

/** @since 1.0.0 */
final class MultiSelectBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/multi-select';
    }

    /** @since 1.0.0 */
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
     * The limits, brought inside what the field can actually satisfy.
     *
     * Read here rather than trusted from the attributes, so a form already
     * published with a minimum above its option count becomes submittable
     * again instead of only new forms being safe.
     *
     * @param array<string,mixed> $attrs
     * @return array{0:int,1:int} [min, max]
     *
     * @since 1.0.0
     */
    public static function limits(array $attrs, int $optionCount): array
    {
        $max = min(max(0, (int) ($attrs['maxSelections'] ?? 0)), $optionCount);
        $min = min(max(0, (int) ($attrs['minSelections'] ?? 0)), $optionCount);

        return [$max > 0 ? min($min, $max) : $min, $max];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $options = DropdownBlock::normalizeOptions($attrs['options'] ?? null);
        $field   = DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? ''));
        [$min, $max] = self::limits($attrs, count($options));

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
