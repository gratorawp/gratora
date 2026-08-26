<?php

declare(strict_types=1);

namespace GiveFlow\Campaigns\Styling;

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
            'giveflow-accent' => [
                'group'   => 'brand',
                'label'   => __('Accent', 'giveflow-fundraising-campaigns'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'giveflow-accent-soft' => [
                'group'   => 'brand',
                'label'   => __('Accent soft', 'giveflow-fundraising-campaigns'),
                'default' => '#efedf8',
                'control' => 'color',
                'help'    => __('Translucent variant used for hover and selected tiles.', 'giveflow-fundraising-campaigns'),
            ],
            'giveflow-text' => [
                'group'   => 'brand',
                'label'   => __('Body text', 'giveflow-fundraising-campaigns'),
                'default' => '#111827',
                'control' => 'color',
            ],
            'giveflow-text-muted' => [
                'group'   => 'brand',
                'label'   => __('Muted text', 'giveflow-fundraising-campaigns'),
                'default' => '#6b7280',
                'control' => 'color',
                'help'    => __('Helper text, placeholders, captions.', 'giveflow-fundraising-campaigns'),
            ],

            'giveflow-bg' => [
                'group'   => 'surface',
                'label'   => __('Background', 'giveflow-fundraising-campaigns'),
                'default' => '#ffffff',
                'control' => 'color',
            ],
            'giveflow-bg-soft' => [
                'group'   => 'surface',
                'label'   => __('Soft background', 'giveflow-fundraising-campaigns'),
                'default' => '#f8fafb',
                'control' => 'color',
                'help'    => __('Input and tile resting fill.', 'giveflow-fundraising-campaigns'),
            ],
            'giveflow-border' => [
                'group'   => 'surface',
                'label'   => __('Border', 'giveflow-fundraising-campaigns'),
                'default' => '#e5e7eb',
                'control' => 'color',
            ],

            'giveflow-typeface' => [
                'group'   => 'typography',
                'label'   => __('Font family', 'giveflow-fundraising-campaigns'),
                'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'control' => 'font',
            ],
            'giveflow-type-size' => [
                'group'   => 'typography',
                'label'   => __('Base font size', 'giveflow-fundraising-campaigns'),
                'default' => '15px',
                'control' => 'select',
                'options' => [
                    '14px' => '14',
                    '15px' => '15',
                    '16px' => '16',
                ],
            ],
            'giveflow-heading-weight' => [
                'group'   => 'typography',
                'label'   => __('Heading weight', 'giveflow-fundraising-campaigns'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'giveflow-fundraising-campaigns'),
                    '600' => __('Semibold', 'giveflow-fundraising-campaigns'),
                    '700' => __('Bold', 'giveflow-fundraising-campaigns'),
                ],
            ],
            'giveflow-body-weight' => [
                'group'   => 'typography',
                'label'   => __('Body weight', 'giveflow-fundraising-campaigns'),
                'default' => '400',
                'control' => 'select',
                'options' => [
                    '400' => __('Regular', 'giveflow-fundraising-campaigns'),
                    '500' => __('Medium', 'giveflow-fundraising-campaigns'),
                ],
            ],

            'giveflow-radius' => [
                'group'   => 'radius',
                'label'   => __('Corner radius', 'giveflow-fundraising-campaigns'),
                'default' => '10px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 24,
                'step'    => 1,
                'help'    => __('Cards, panels and other surfaces.', 'giveflow-fundraising-campaigns'),
            ],
            'giveflow-radius-sm' => [
                'group'   => 'radius',
                'label'   => __('Small corner radius', 'giveflow-fundraising-campaigns'),
                'default' => '8px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 16,
                'step'    => 1,
                'help'    => __('Buttons, inputs, chips and other controls.', 'giveflow-fundraising-campaigns'),
            ],
            // Not 'giveflow-border-width'. Token names ship inside a block's inline
            // style attribute, and themes select on substrings of it:
            // twentytwentyfive's `html :where([style*="border-width"])` matches
            // the custom property and draws a border on every campaign block.
            // Same for giveflow-typeface, giveflow-type-size and giveflow-button-size: no
            // token name may contain a CSS property a [style*=] selector targets.
            'giveflow-stroke' => [
                'group'   => 'radius',
                'label'   => __('Border width', 'giveflow-fundraising-campaigns'),
                'default' => '1px',
                'control' => 'select',
                'options' => [
                    '1px' => '1px',
                    '2px' => '2px',
                ],
            ],

            'giveflow-gap' => [
                'group'   => 'spacing',
                'label'   => __('Block spacing', 'giveflow-fundraising-campaigns'),
                'default' => '20px',
                'control' => 'range',
                'min'     => 12,
                'max'     => 32,
                'step'    => 2,
                'help'    => __('Vertical rhythm between blocks.', 'giveflow-fundraising-campaigns'),
            ],
            'giveflow-field-gap' => [
                'group'   => 'spacing',
                'label'   => __('Label gap', 'giveflow-fundraising-campaigns'),
                'default' => '6px',
                'control' => 'range',
                'min'     => 4,
                'max'     => 12,
                'step'    => 1,
            ],

            'giveflow-button-size' => [
                'group'   => 'buttons',
                'label'   => __('Button height', 'giveflow-fundraising-campaigns'),
                'default' => '48px',
                'control' => 'range',
                'min'     => 40,
                'max'     => 60,
                'step'    => 2,
            ],
            'giveflow-button-weight' => [
                'group'   => 'buttons',
                'label'   => __('Button text weight', 'giveflow-fundraising-campaigns'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'giveflow-fundraising-campaigns'),
                    '600' => __('Semibold', 'giveflow-fundraising-campaigns'),
                    '700' => __('Bold', 'giveflow-fundraising-campaigns'),
                ],
            ],
            'giveflow-button-shadow' => [
                'group'   => 'buttons',
                'label'   => __('Button shadow', 'giveflow-fundraising-campaigns'),
                'default' => '0 1px 2px rgba(0,0,0,.08)',
                'control' => 'select',
                'options' => [
                    'none'                                  => __('None', 'giveflow-fundraising-campaigns'),
                    '0 1px 2px rgba(0,0,0,.08)'             => __('Soft', 'giveflow-fundraising-campaigns'),
                    '0 6px 16px rgba(0,0,0,.12)'            => __('Strong', 'giveflow-fundraising-campaigns'),
                ],
            ],
            'giveflow-button-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button background', 'giveflow-fundraising-campaigns'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use the accent color.', 'giveflow-fundraising-campaigns'),
            ],
            'giveflow-button-fg' => [
                'group'   => 'buttons',
                'label'   => __('Button text color', 'giveflow-fundraising-campaigns'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use white on filled buttons.', 'giveflow-fundraising-campaigns'),
            ],
            'giveflow-button-border' => [
                'group'   => 'buttons',
                'label'   => __('Button border', 'giveflow-fundraising-campaigns'),
                'default' => '0',
                'control' => 'select',
                'options' => [
                    '0'                       => __('None (filled)', 'giveflow-fundraising-campaigns'),
                    '1px solid currentColor'  => __('Outline thin', 'giveflow-fundraising-campaigns'),
                    '2px solid currentColor'  => __('Outline thick', 'giveflow-fundraising-campaigns'),
                ],
            ],
            'giveflow-button-hover-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button hover background', 'giveflow-fundraising-campaigns'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to inherit the button background.', 'giveflow-fundraising-campaigns'),
            ],

            'giveflow-focus-ring' => [
                'group'   => 'elevation',
                'label'   => __('Focus ring color', 'giveflow-fundraising-campaigns'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'giveflow-card-shadow' => [
                'group'   => 'elevation',
                'label'   => __('Card shadow', 'giveflow-fundraising-campaigns'),
                'default' => '0 12px 32px rgba(15, 23, 42, .06)',
                'control' => 'select',
                'options' => [
                    'none'                                       => __('None', 'giveflow-fundraising-campaigns'),
                    '0 1px 2px rgba(15, 23, 42, .04)'            => __('Soft', 'giveflow-fundraising-campaigns'),
                    '0 12px 32px rgba(15, 23, 42, .06)'          => __('Floating', 'giveflow-fundraising-campaigns'),
                    '0 30px 60px rgba(0, 0, 0, .25)'             => __('Dramatic', 'giveflow-fundraising-campaigns'),
                ],
            ],
        ];
    }

    /** @since 1.0.0 */
    public static function groups(): array
    {
        return [
            'brand'      => __('Brand colors', 'giveflow-fundraising-campaigns'),
            'surface'    => __('Surface', 'giveflow-fundraising-campaigns'),
            'typography' => __('Typography', 'giveflow-fundraising-campaigns'),
            'radius'     => __('Radius + borders', 'giveflow-fundraising-campaigns'),
            'spacing'    => __('Spacing', 'giveflow-fundraising-campaigns'),
            'buttons'    => __('Buttons', 'giveflow-fundraising-campaigns'),
            'elevation'  => __('Focus + elevation', 'giveflow-fundraising-campaigns'),
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
