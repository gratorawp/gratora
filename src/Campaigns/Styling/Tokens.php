<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

/**
 * Each token maps to a CSS custom property injected on the rendered form element.
 *
 * @since 1.0.0
 */
final class Tokens
{
    /**
     * @return array<string, array{
     *   group: string,
     *   label: string,
     *   default: string,
     *   control: string,
     *   min?: int|float,
     *   max?: int|float,
     *   step?: int|float,
     *   options?: array<string,string>,
     *   help?: string
     * }>
     *
     * @since 1.0.0
     */
    public static function catalogue(): array
    {
        return [
            'dono-accent' => [
                'group'   => 'brand',
                'label'   => __('Accent', 'dono-fundraising-platform'),
                'default' => '#1e8a4e',
                'control' => 'color',
            ],
            'dono-accent-soft' => [
                'group'   => 'brand',
                'label'   => __('Accent soft', 'dono-fundraising-platform'),
                'default' => '#e7f5ec',
                'control' => 'color',
                'help'    => __('Translucent variant used for hover and selected tiles.', 'dono-fundraising-platform'),
            ],
            'dono-text' => [
                'group'   => 'brand',
                'label'   => __('Body text', 'dono-fundraising-platform'),
                'default' => '#111827',
                'control' => 'color',
            ],
            'dono-text-muted' => [
                'group'   => 'brand',
                'label'   => __('Muted text', 'dono-fundraising-platform'),
                'default' => '#6b7280',
                'control' => 'color',
                'help'    => __('Helper text, placeholders, captions.', 'dono-fundraising-platform'),
            ],

            'dono-bg' => [
                'group'   => 'surface',
                'label'   => __('Background', 'dono-fundraising-platform'),
                'default' => '#ffffff',
                'control' => 'color',
            ],
            'dono-bg-soft' => [
                'group'   => 'surface',
                'label'   => __('Soft background', 'dono-fundraising-platform'),
                'default' => '#f8fafb',
                'control' => 'color',
                'help'    => __('Input and tile resting fill.', 'dono-fundraising-platform'),
            ],
            'dono-border' => [
                'group'   => 'surface',
                'label'   => __('Border', 'dono-fundraising-platform'),
                'default' => '#e5e7eb',
                'control' => 'color',
            ],

            'dono-typeface' => [
                'group'   => 'typography',
                'label'   => __('Font family', 'dono-fundraising-platform'),
                'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'control' => 'font',
            ],
            'dono-type-size' => [
                'group'   => 'typography',
                'label'   => __('Base font size', 'dono-fundraising-platform'),
                'default' => '15px',
                'control' => 'select',
                'options' => [
                    '14px' => '14',
                    '15px' => '15',
                    '16px' => '16',
                ],
            ],
            'dono-heading-weight' => [
                'group'   => 'typography',
                'label'   => __('Heading weight', 'dono-fundraising-platform'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'dono-fundraising-platform'),
                    '600' => __('Semibold', 'dono-fundraising-platform'),
                    '700' => __('Bold', 'dono-fundraising-platform'),
                ],
            ],
            'dono-body-weight' => [
                'group'   => 'typography',
                'label'   => __('Body weight', 'dono-fundraising-platform'),
                'default' => '400',
                'control' => 'select',
                'options' => [
                    '400' => __('Regular', 'dono-fundraising-platform'),
                    '500' => __('Medium', 'dono-fundraising-platform'),
                ],
            ],

            'dono-radius' => [
                'group'   => 'radius',
                'label'   => __('Corner radius', 'dono-fundraising-platform'),
                'default' => '10px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 24,
                'step'    => 1,
                'help'    => __('Cards, panels and other surfaces.', 'dono-fundraising-platform'),
            ],
            'dono-radius-sm' => [
                'group'   => 'radius',
                'label'   => __('Small corner radius', 'dono-fundraising-platform'),
                'default' => '8px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 16,
                'step'    => 1,
                'help'    => __('Buttons, inputs, chips and other controls.', 'dono-fundraising-platform'),
            ],
            // Not 'dono-border-width'. Token names ship inside a block's inline
            // style attribute, and themes select on substrings of it:
            // twentytwentyfive's `html :where([style*="border-width"])` matches
            // the custom property and draws a border on every campaign block.
            // Same for dono-typeface, dono-type-size and dono-button-size: no
            // token name may contain a CSS property a [style*=] selector targets.
            'dono-stroke' => [
                'group'   => 'radius',
                'label'   => __('Border width', 'dono-fundraising-platform'),
                'default' => '1px',
                'control' => 'select',
                'options' => [
                    '1px' => '1px',
                    '2px' => '2px',
                ],
            ],

            'dono-gap' => [
                'group'   => 'spacing',
                'label'   => __('Block spacing', 'dono-fundraising-platform'),
                'default' => '20px',
                'control' => 'range',
                'min'     => 12,
                'max'     => 32,
                'step'    => 2,
                'help'    => __('Vertical rhythm between blocks.', 'dono-fundraising-platform'),
            ],
            'dono-field-gap' => [
                'group'   => 'spacing',
                'label'   => __('Label gap', 'dono-fundraising-platform'),
                'default' => '6px',
                'control' => 'range',
                'min'     => 4,
                'max'     => 12,
                'step'    => 1,
            ],

            'dono-button-size' => [
                'group'   => 'buttons',
                'label'   => __('Button height', 'dono-fundraising-platform'),
                'default' => '48px',
                'control' => 'range',
                'min'     => 40,
                'max'     => 60,
                'step'    => 2,
            ],
            'dono-button-weight' => [
                'group'   => 'buttons',
                'label'   => __('Button text weight', 'dono-fundraising-platform'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'dono-fundraising-platform'),
                    '600' => __('Semibold', 'dono-fundraising-platform'),
                    '700' => __('Bold', 'dono-fundraising-platform'),
                ],
            ],
            'dono-button-shadow' => [
                'group'   => 'buttons',
                'label'   => __('Button shadow', 'dono-fundraising-platform'),
                'default' => '0 1px 2px rgba(0,0,0,.08)',
                'control' => 'select',
                'options' => [
                    'none'                                  => __('None', 'dono-fundraising-platform'),
                    '0 1px 2px rgba(0,0,0,.08)'             => __('Soft', 'dono-fundraising-platform'),
                    '0 6px 16px rgba(0,0,0,.12)'            => __('Strong', 'dono-fundraising-platform'),
                ],
            ],
            'dono-button-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button background', 'dono-fundraising-platform'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use the accent color.', 'dono-fundraising-platform'),
            ],
            'dono-button-fg' => [
                'group'   => 'buttons',
                'label'   => __('Button text color', 'dono-fundraising-platform'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use white on filled buttons.', 'dono-fundraising-platform'),
            ],
            'dono-button-border' => [
                'group'   => 'buttons',
                'label'   => __('Button border', 'dono-fundraising-platform'),
                'default' => '0',
                'control' => 'select',
                'options' => [
                    '0'                       => __('None (filled)', 'dono-fundraising-platform'),
                    '1px solid currentColor'  => __('Outline thin', 'dono-fundraising-platform'),
                    '2px solid currentColor'  => __('Outline thick', 'dono-fundraising-platform'),
                ],
            ],
            'dono-button-hover-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button hover background', 'dono-fundraising-platform'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to inherit the button background.', 'dono-fundraising-platform'),
            ],

            'dono-focus-ring' => [
                'group'   => 'elevation',
                'label'   => __('Focus ring color', 'dono-fundraising-platform'),
                'default' => '#1e8a4e',
                'control' => 'color',
            ],
            'dono-card-shadow' => [
                'group'   => 'elevation',
                'label'   => __('Card shadow', 'dono-fundraising-platform'),
                'default' => '0 12px 32px rgba(15, 23, 42, .06)',
                'control' => 'select',
                'options' => [
                    'none'                                       => __('None', 'dono-fundraising-platform'),
                    '0 1px 2px rgba(15, 23, 42, .04)'            => __('Soft', 'dono-fundraising-platform'),
                    '0 12px 32px rgba(15, 23, 42, .06)'          => __('Floating', 'dono-fundraising-platform'),
                    '0 30px 60px rgba(0, 0, 0, .25)'             => __('Dramatic', 'dono-fundraising-platform'),
                ],
            ],
        ];
    }

    /** @since 1.0.0 */
    public static function groups(): array
    {
        return [
            'brand'      => __('Brand colors', 'dono-fundraising-platform'),
            'surface'    => __('Surface', 'dono-fundraising-platform'),
            'typography' => __('Typography', 'dono-fundraising-platform'),
            'radius'     => __('Radius + borders', 'dono-fundraising-platform'),
            'spacing'    => __('Spacing', 'dono-fundraising-platform'),
            'buttons'    => __('Buttons', 'dono-fundraising-platform'),
            'elevation'  => __('Focus + elevation', 'dono-fundraising-platform'),
        ];
    }

    /** @since 1.0.0 */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::catalogue() as $key => $def) {
            $out[$key] = (string) $def['default'];
        }
        return $out;
    }

    /**
     * Values land verbatim in CSS, where `;` or `}` breaks out of the
     * declaration, so each is validated against its control's expected shape.
     *
     * @since 1.0.0
     */
    public static function sanitize(array $tokens): array
    {
        $out = [];
        $catalogue = self::catalogue();
        foreach ($tokens as $key => $value) {
            if (! isset($catalogue[$key])) continue;
            $val = is_scalar($value) ? trim((string) $value) : '';
            if ($val === '') continue;

            $sanitised = self::sanitiseValue($catalogue[$key], $val);
            if ($sanitised !== null) $out[$key] = $sanitised;
        }
        return $out;
    }

    /**
     * Null drops the value.
     *
     * @since 1.0.0
     */
    private static function sanitiseValue(array $def, string $value): ?string
    {
        if (preg_match('/[;{}<>\\\\]/', $value)) return null;

        $control = (string) ($def['control'] ?? '');

        switch ($control) {
            case 'color':
                $hex = sanitize_hex_color($value);
                if (is_string($hex) && $hex !== '') return $hex;
                // sanitize_hex_color rejects 4/8-digit hex; accept the alpha
                // variants explicitly.
                if (preg_match('/^#(?:[0-9a-fA-F]{4}|[0-9a-fA-F]{8})$/', $value)) return strtolower($value);
                if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,\s%\/-]+\s*\)$/i', $value)) return $value;
                // Keywords used by outlined / inherited button presets.
                if (in_array(strtolower($value), ['transparent', 'currentcolor', 'inherit'], true)) {
                    return strtolower($value) === 'currentcolor' ? 'currentColor' : strtolower($value);
                }
                return null;

            case 'range':
                if (preg_match('/^-?\d+(\.\d+)?(px|em|rem|%)?$/', $value)) return $value;
                return null;

            case 'select':
                $options = is_array($def['options'] ?? null) ? $def['options'] : [];
                return isset($options[$value]) ? $value : null;

            case 'font':
                if (preg_match('/^[a-zA-Z0-9_\-\s,"\'.]+$/u', $value)) return $value;
                return null;
        }

        return null;
    }
}
