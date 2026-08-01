<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

/**
 * Canonical donation-form style token catalogue. Each token maps to a CSS
 * custom property injected on the rendered form element.
 *
 * @version 1.0.0
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
     */
    public static function catalogue(): array
    {
        return [
            'dono-accent' => [
                'group'   => 'brand',
                'label'   => __('Accent', 'dono'),
                'default' => '#1e8a4e',
                'control' => 'color',
            ],
            'dono-accent-soft' => [
                'group'   => 'brand',
                'label'   => __('Accent soft', 'dono'),
                'default' => '#e7f5ec',
                'control' => 'color',
                'help'    => __('Translucent variant used for hover and selected tiles.', 'dono'),
            ],
            'dono-text' => [
                'group'   => 'brand',
                'label'   => __('Body text', 'dono'),
                'default' => '#111827',
                'control' => 'color',
            ],
            'dono-text-muted' => [
                'group'   => 'brand',
                'label'   => __('Muted text', 'dono'),
                'default' => '#6b7280',
                'control' => 'color',
                'help'    => __('Helper text, placeholders, captions.', 'dono'),
            ],

            'dono-bg' => [
                'group'   => 'surface',
                'label'   => __('Background', 'dono'),
                'default' => '#ffffff',
                'control' => 'color',
            ],
            'dono-bg-soft' => [
                'group'   => 'surface',
                'label'   => __('Soft background', 'dono'),
                'default' => '#f8fafb',
                'control' => 'color',
                'help'    => __('Input and tile resting fill.', 'dono'),
            ],
            'dono-border' => [
                'group'   => 'surface',
                'label'   => __('Border', 'dono'),
                'default' => '#e5e7eb',
                'control' => 'color',
            ],

            'dono-font-family' => [
                'group'   => 'typography',
                'label'   => __('Font family', 'dono'),
                'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'control' => 'font',
            ],
            'dono-font-size' => [
                'group'   => 'typography',
                'label'   => __('Base font size', 'dono'),
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
                'label'   => __('Heading weight', 'dono'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'dono'),
                    '600' => __('Semibold', 'dono'),
                    '700' => __('Bold', 'dono'),
                ],
            ],
            'dono-body-weight' => [
                'group'   => 'typography',
                'label'   => __('Body weight', 'dono'),
                'default' => '400',
                'control' => 'select',
                'options' => [
                    '400' => __('Regular', 'dono'),
                    '500' => __('Medium', 'dono'),
                ],
            ],

            'dono-radius' => [
                'group'   => 'radius',
                'label'   => __('Corner radius', 'dono'),
                'default' => '10px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 24,
                'step'    => 1,
                'help'    => __('Cards, panels and other surfaces.', 'dono'),
            ],
            'dono-radius-sm' => [
                'group'   => 'radius',
                'label'   => __('Small corner radius', 'dono'),
                'default' => '8px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 16,
                'step'    => 1,
                'help'    => __('Buttons, inputs, chips and other controls.', 'dono'),
            ],
            'dono-radius-lg' => [
                'group'   => 'radius',
                'label'   => __('Large corner radius', 'dono'),
                'default' => '18px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 36,
                'step'    => 1,
                'help'    => __('The campaign hero and other large surfaces.', 'dono'),
            ],
            'dono-border-width' => [
                'group'   => 'radius',
                'label'   => __('Border width', 'dono'),
                'default' => '1px',
                'control' => 'select',
                'options' => [
                    '1px' => '1px',
                    '2px' => '2px',
                ],
            ],

            'dono-gap' => [
                'group'   => 'spacing',
                'label'   => __('Block spacing', 'dono'),
                'default' => '20px',
                'control' => 'range',
                'min'     => 12,
                'max'     => 32,
                'step'    => 2,
                'help'    => __('Vertical rhythm between blocks.', 'dono'),
            ],
            'dono-field-gap' => [
                'group'   => 'spacing',
                'label'   => __('Label gap', 'dono'),
                'default' => '6px',
                'control' => 'range',
                'min'     => 4,
                'max'     => 12,
                'step'    => 1,
            ],

            'dono-button-height' => [
                'group'   => 'buttons',
                'label'   => __('Button height', 'dono'),
                'default' => '48px',
                'control' => 'range',
                'min'     => 40,
                'max'     => 60,
                'step'    => 2,
            ],
            'dono-button-weight' => [
                'group'   => 'buttons',
                'label'   => __('Button text weight', 'dono'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'dono'),
                    '600' => __('Semibold', 'dono'),
                    '700' => __('Bold', 'dono'),
                ],
            ],
            'dono-button-shadow' => [
                'group'   => 'buttons',
                'label'   => __('Button shadow', 'dono'),
                'default' => '0 1px 2px rgba(0,0,0,.08)',
                'control' => 'select',
                'options' => [
                    'none'                                  => __('None', 'dono'),
                    '0 1px 2px rgba(0,0,0,.08)'             => __('Soft', 'dono'),
                    '0 6px 16px rgba(0,0,0,.12)'            => __('Strong', 'dono'),
                ],
            ],
            'dono-button-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button background', 'dono'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use the accent color.', 'dono'),
            ],
            'dono-button-fg' => [
                'group'   => 'buttons',
                'label'   => __('Button text color', 'dono'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use white on filled buttons.', 'dono'),
            ],
            'dono-button-border' => [
                'group'   => 'buttons',
                'label'   => __('Button border', 'dono'),
                'default' => '0',
                'control' => 'select',
                'options' => [
                    '0'                       => __('None (filled)', 'dono'),
                    '1px solid currentColor'  => __('Outline thin', 'dono'),
                    '2px solid currentColor'  => __('Outline thick', 'dono'),
                ],
            ],
            'dono-button-hover-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button hover background', 'dono'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to inherit the button background.', 'dono'),
            ],

            'dono-focus-ring' => [
                'group'   => 'elevation',
                'label'   => __('Focus ring color', 'dono'),
                'default' => '#1e8a4e',
                'control' => 'color',
            ],
            'dono-card-shadow' => [
                'group'   => 'elevation',
                'label'   => __('Card shadow', 'dono'),
                'default' => '0 12px 32px rgba(15, 23, 42, .06)',
                'control' => 'select',
                'options' => [
                    'none'                                       => __('None', 'dono'),
                    '0 1px 2px rgba(15, 23, 42, .04)'            => __('Soft', 'dono'),
                    '0 12px 32px rgba(15, 23, 42, .06)'          => __('Floating', 'dono'),
                    '0 30px 60px rgba(0, 0, 0, .25)'             => __('Dramatic', 'dono'),
                ],
            ],
        ];
    }

    /** Group slug → display label. */
    public static function groups(): array
    {
        return [
            'brand'      => __('Brand colors', 'dono'),
            'surface'    => __('Surface', 'dono'),
            'typography' => __('Typography', 'dono'),
            'radius'     => __('Radius + borders', 'dono'),
            'spacing'    => __('Spacing', 'dono'),
            'buttons'    => __('Buttons', 'dono'),
            'elevation'  => __('Focus + elevation', 'dono'),
        ];
    }

    /** Full default token map (every token at its catalogue default). */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::catalogue() as $key => $def) {
            $out[$key] = (string) $def['default'];
        }
        return $out;
    }

    /**
     * Sanitize an inbound token map: drop unknown keys, coerce values to strings,
     * drop empties. Values land verbatim in CSS; `;` or `}` would break out of
     * the declaration, so each is validated against its control's expected shape.
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
     * Validate a single token value against its catalogue definition. Returns
     * the value if it's safe, or null to drop it. Catches values that could
     * break out of a CSS declaration up front, then tightens per control.
     *
     * @param array<string,mixed> $def
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
                // Accept CSS color keywords used by outlined / inherited
                // button presets (Quiet's transparent fill, etc.).
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
