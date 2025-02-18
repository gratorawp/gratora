<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Single-select dropdown field block.
 *
 * @version 1.0.0
 */
final class DropdownBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/dropdown';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'options'     => ['type' => 'array',   'default' => [
                ['label' => 'Option one', 'value' => 'option-one', 'isDefault' => false],
            ]],
            'required'    => ['type' => 'boolean', 'default' => false],
            'field'       => ['type' => 'string',  'default' => ''],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $options = self::normalizeOptions($attrs['options'] ?? null);
        $field   = self::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? ''));
        $default = '';
        foreach ($options as $o) {
            if (! empty($o['isDefault'])) { $default = $o['value']; break; }
        }

        return View::loadRelative(__DIR__, 'views/dropdown', [
            'label'       => (string) ($attrs['label']       ?? ''),
            'placeholder' => (string) ($attrs['placeholder'] ?? ''),
            'options'     => $options,
            'required'    => (bool)   ($attrs['required']    ?? false),
            'field'       => $field,
            'defaultValue' => $default,
        ]);
    }

    /** @return list<array{label:string,value:string,isDefault:bool}> */
    public static function normalizeOptions(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        $out   = [];
        $seen  = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) continue;
            $label = (string) ($item['label'] ?? '');
            $value = (string) ($item['value'] ?? '');
            $value = self::slugify($value !== '' ? $value : $label);
            if ($value === '') $value = 'option-' . ($i + 1);
            if (isset($seen[$value])) $value = $value . '-' . ($i + 1);
            $seen[$value] = true;
            $out[] = [
                'label'     => $label,
                'value'     => $value,
                'isDefault' => (bool) ($item['isDefault'] ?? false),
            ];
        }
        if (empty($out)) {
            $out = [['label' => 'Option one', 'value' => 'option-one', 'isDefault' => false]];
        }
        return $out;
    }

    /** Derive a snake_case field key from an explicit field or the label. */
    public static function deriveField(string $field, string $label): string
    {
        $f = self::slugifySnake($field);
        if ($f !== '') return $f;
        $f = self::slugifySnake($label);
        return $f !== '' ? $f : 'field';
    }

    /** Slugify to a hyphenated value. */
    private static function slugify(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** Slugify to snake_case. */
    private static function slugifySnake(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        return trim($s, '_');
    }
}
