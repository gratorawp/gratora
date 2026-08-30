<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Styling;

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
            'fundkit-accent' => [
                'group'   => 'brand',
                'label'   => __('Accent', 'fundkit-fundraising-campaigns'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'fundkit-accent-soft' => [
                'group'   => 'brand',
                'label'   => __('Accent soft', 'fundkit-fundraising-campaigns'),
                'default' => '#efedf8',
                'control' => 'color',
                'help'    => __('Translucent variant used for hover and selected tiles.', 'fundkit-fundraising-campaigns'),
            ],
            'fundkit-text' => [
                'group'   => 'brand',
                'label'   => __('Body text', 'fundkit-fundraising-campaigns'),
                'default' => '#111827',
                'control' => 'color',
            ],
            'fundkit-text-muted' => [
                'group'   => 'brand',
                'label'   => __('Muted text', 'fundkit-fundraising-campaigns'),
                'default' => '#6b7280',
                'control' => 'color',
                'help'    => __('Helper text, placeholders, captions.', 'fundkit-fundraising-campaigns'),
            ],

            'fundkit-bg' => [
                'group'   => 'surface',
                'label'   => __('Background', 'fundkit-fundraising-campaigns'),
                'default' => '#ffffff',
                'control' => 'color',
            ],
            'fundkit-bg-soft' => [
                'group'   => 'surface',
                'label'   => __('Soft background', 'fundkit-fundraising-campaigns'),
                'default' => '#f8fafb',
                'control' => 'color',
                'help'    => __('Input and tile resting fill.', 'fundkit-fundraising-campaigns'),
            ],
            'fundkit-border' => [
                'group'   => 'surface',
                'label'   => __('Border', 'fundkit-fundraising-campaigns'),
                'default' => '#e5e7eb',
                'control' => 'color',
            ],

            'fundkit-typeface' => [
                'group'   => 'typography',
                'label'   => __('Font family', 'fundkit-fundraising-campaigns'),
                'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'control' => 'font',
            ],
            'fundkit-type-size' => [
                'group'   => 'typography',
                'label'   => __('Base font size', 'fundkit-fundraising-campaigns'),
                'default' => '15px',
                'control' => 'select',
                'options' => [
                    '14px' => '14',
                    '15px' => '15',
                    '16px' => '16',
                ],
            ],
            'fundkit-heading-weight' => [
                'group'   => 'typography',
                'label'   => __('Heading weight', 'fundkit-fundraising-campaigns'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'fundkit-fundraising-campaigns'),
                    '600' => __('Semibold', 'fundkit-fundraising-campaigns'),
                    '700' => __('Bold', 'fundkit-fundraising-campaigns'),
                ],
            ],
            'fundkit-body-weight' => [
                'group'   => 'typography',
                'label'   => __('Body weight', 'fundkit-fundraising-campaigns'),
                'default' => '400',
                'control' => 'select',
                'options' => [
                    '400' => __('Regular', 'fundkit-fundraising-campaigns'),
                    '500' => __('Medium', 'fundkit-fundraising-campaigns'),
                ],
            ],

            'fundkit-radius' => [
                'group'   => 'radius',
                'label'   => __('Corner radius', 'fundkit-fundraising-campaigns'),
                'default' => '10px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 24,
                'step'    => 1,
                'help'    => __('Cards, panels and other surfaces.', 'fundkit-fundraising-campaigns'),
            ],
            'fundkit-radius-sm' => [
                'group'   => 'radius',
                'label'   => __('Small corner radius', 'fundkit-fundraising-campaigns'),
                'default' => '8px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 16,
                'step'    => 1,
                'help'    => __('Buttons, inputs, chips and other controls.', 'fundkit-fundraising-campaigns'),
            ],
            // Not 'fundkit-border-width'. Token names ship inside a block's inline
            // style attribute, and themes select on substrings of it:
            // twentytwentyfive's `html :where([style*="border-width"])` matches
            // the custom property and draws a border on every campaign block.
            // Same for fundkit-typeface, fundkit-type-size and fundkit-button-size: no
            // token name may contain a CSS property a [style*=] selector targets.
            'fundkit-stroke' => [
                'group'   => 'radius',
                'label'   => __('Border width', 'fundkit-fundraising-campaigns'),
                'default' => '1px',
                'control' => 'select',
                'options' => [
                    '1px' => '1px',
                    '2px' => '2px',
                ],
            ],

            'fundkit-gap' => [
                'group'   => 'spacing',
                'label'   => __('Block spacing', 'fundkit-fundraising-campaigns'),
                'default' => '20px',
                'control' => 'range',
                'min'     => 12,
                'max'     => 32,
                'step'    => 2,
                'help'    => __('Vertical rhythm between blocks.', 'fundkit-fundraising-campaigns'),
            ],
            'fundkit-field-gap' => [
                'group'   => 'spacing',
                'label'   => __('Label gap', 'fundkit-fundraising-campaigns'),
                'default' => '6px',
                'control' => 'range',
                'min'     => 4,
                'max'     => 12,
                'step'    => 1,
            ],

            'fundkit-button-size' => [
                'group'   => 'buttons',
                'label'   => __('Button height', 'fundkit-fundraising-campaigns'),
                'default' => '48px',
                'control' => 'range',
                'min'     => 40,
                'max'     => 60,
                'step'    => 2,
            ],
            'fundkit-button-weight' => [
                'group'   => 'buttons',
                'label'   => __('Button text weight', 'fundkit-fundraising-campaigns'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'fundkit-fundraising-campaigns'),
                    '600' => __('Semibold', 'fundkit-fundraising-campaigns'),
                    '700' => __('Bold', 'fundkit-fundraising-campaigns'),
                ],
            ],
            'fundkit-button-shadow' => [
                'group'   => 'buttons',
                'label'   => __('Button shadow', 'fundkit-fundraising-campaigns'),
                'default' => '0 1px 2px rgba(0,0,0,.08)',
                'control' => 'select',
                'options' => [
                    'none'                                  => __('None', 'fundkit-fundraising-campaigns'),
                    '0 1px 2px rgba(0,0,0,.08)'             => __('Soft', 'fundkit-fundraising-campaigns'),
                    '0 6px 16px rgba(0,0,0,.12)'            => __('Strong', 'fundkit-fundraising-campaigns'),
                ],
            ],
            'fundkit-button-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button background', 'fundkit-fundraising-campaigns'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use the accent color.', 'fundkit-fundraising-campaigns'),
            ],
            'fundkit-button-fg' => [
                'group'   => 'buttons',
                'label'   => __('Button text color', 'fundkit-fundraising-campaigns'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use white on filled buttons.', 'fundkit-fundraising-campaigns'),
            ],
            'fundkit-button-border' => [
                'group'   => 'buttons',
                'label'   => __('Button border', 'fundkit-fundraising-campaigns'),
                'default' => '0',
                'control' => 'select',
                'options' => [
                    '0'                       => __('None (filled)', 'fundkit-fundraising-campaigns'),
                    '1px solid currentColor'  => __('Outline thin', 'fundkit-fundraising-campaigns'),
                    '2px solid currentColor'  => __('Outline thick', 'fundkit-fundraising-campaigns'),
                ],
            ],
            'fundkit-button-hover-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button hover background', 'fundkit-fundraising-campaigns'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to inherit the button background.', 'fundkit-fundraising-campaigns'),
            ],

            'fundkit-focus-ring' => [
                'group'   => 'elevation',
                'label'   => __('Focus ring color', 'fundkit-fundraising-campaigns'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'fundkit-card-shadow' => [
                'group'   => 'elevation',
                'label'   => __('Card shadow', 'fundkit-fundraising-campaigns'),
                'default' => '0 12px 32px rgba(15, 23, 42, .06)',
                'control' => 'select',
                'options' => [
                    'none'                                       => __('None', 'fundkit-fundraising-campaigns'),
                    '0 1px 2px rgba(15, 23, 42, .04)'            => __('Soft', 'fundkit-fundraising-campaigns'),
                    '0 12px 32px rgba(15, 23, 42, .06)'          => __('Floating', 'fundkit-fundraising-campaigns'),
                    '0 30px 60px rgba(0, 0, 0, .25)'             => __('Dramatic', 'fundkit-fundraising-campaigns'),
                ],
            ],
        ];
    }

    /** @since 1.0.0 */
    public static function groups(): array
    {
        return [
            'brand'      => __('Brand colors', 'fundkit-fundraising-campaigns'),
            'surface'    => __('Surface', 'fundkit-fundraising-campaigns'),
            'typography' => __('Typography', 'fundkit-fundraising-campaigns'),
            'radius'     => __('Radius + borders', 'fundkit-fundraising-campaigns'),
            'spacing'    => __('Spacing', 'fundkit-fundraising-campaigns'),
            'buttons'    => __('Buttons', 'fundkit-fundraising-campaigns'),
            'elevation'  => __('Focus + elevation', 'fundkit-fundraising-campaigns'),
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
