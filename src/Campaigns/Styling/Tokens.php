<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Styling;

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
     *   options?: array<array-key,string>,
     *   help?: string
     * }>
     *
     * @since 1.0.0
     */
    /**
     * Tokens a preset may set that are not admin controls. Each is deliberately
     * left out of catalogue(), and so out of defaults(), because unset is what
     * makes it inherit: --gratora-button-radius falls through to
     * --gratora-radius-sm, so an org that rounds its controls rounds its button
     * with them. A default here would pin the button and break that, but
     * sanitize() has to keep the key or Classic loses its pill on the first
     * save of the brand panel.
     *
     * 'inherits' writes that fall-through out, for a surface nested inside one
     * that has already declared the property and would otherwise lend it.
     *
     * @var array<string, array{control: string, inherits: string}>
     */
    private const PASS_THROUGH = [
        'gratora-button-radius'   => ['control' => 'range', 'inherits' => 'var(--gratora-radius-sm, 8px)'],
        'gratora-switcher-radius' => ['control' => 'range', 'inherits' => 'var(--gratora-radius-sm, 8px)'],
    ];

    public static function catalogue(): array
    {
        return [
            'gratora-accent' => [
                'group'   => 'brand',
                'label'   => __('Accent', 'gratora'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'gratora-accent-soft' => [
                'group'   => 'brand',
                'label'   => __('Accent soft', 'gratora'),
                'default' => '#efedf8',
                'control' => 'color',
                'help'    => __('Translucent variant used for hover and selected tiles.', 'gratora'),
            ],
            'gratora-text' => [
                'group'   => 'brand',
                'label'   => __('Body text', 'gratora'),
                'default' => '#111827',
                'control' => 'color',
            ],
            'gratora-text-muted' => [
                'group'   => 'brand',
                'label'   => __('Muted text', 'gratora'),
                'default' => '#6b7280',
                'control' => 'color',
                'help'    => __('Helper text, placeholders, captions.', 'gratora'),
            ],

            'gratora-bg' => [
                'group'   => 'surface',
                'label'   => __('Background', 'gratora'),
                'default' => '#ffffff',
                'control' => 'color',
                'help'    => __('The card behind the form, and the panels on the campaign page.', 'gratora'),
            ],
            'gratora-field-bg' => [
                'group'   => 'surface',
                'label'   => __('Field background', 'gratora'),
                'default' => '#ffffff',
                'control' => 'color',
                'help'    => __('Inside the boxes a donor types in or picks from.', 'gratora'),
            ],
            'gratora-bg-soft' => [
                'group'   => 'surface',
                'label'   => __('Soft background', 'gratora'),
                'default' => '#f8fafb',
                'control' => 'color',
                'help'    => __('Amount tile resting fill.', 'gratora'),
            ],
            'gratora-border' => [
                'group'   => 'surface',
                'label'   => __('Border', 'gratora'),
                'default' => '#e5e7eb',
                'control' => 'color',
            ],

            'gratora-typeface' => [
                'group'   => 'typography',
                'label'   => __('Font family', 'gratora'),
                'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'control' => 'font',
            ],
            'gratora-type-size' => [
                'group'   => 'typography',
                'label'   => __('Base font size', 'gratora'),
                'default' => '15px',
                'control' => 'select',
                'options' => [
                    '14px' => '14',
                    '15px' => '15',
                    '16px' => '16',
                ],
            ],
            'gratora-heading-weight' => [
                'group'   => 'typography',
                'label'   => __('Heading weight', 'gratora'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'gratora'),
                    '600' => __('Semibold', 'gratora'),
                    '700' => __('Bold', 'gratora'),
                ],
            ],
            'gratora-body-weight' => [
                'group'   => 'typography',
                'label'   => __('Body weight', 'gratora'),
                'default' => '400',
                'control' => 'select',
                'options' => [
                    '400' => __('Regular', 'gratora'),
                    '500' => __('Medium', 'gratora'),
                ],
            ],

            'gratora-radius' => [
                'group'   => 'radius',
                'label'   => __('Corner radius', 'gratora'),
                'default' => '10px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 24,
                'step'    => 1,
                'help'    => __('Cards, panels and other surfaces.', 'gratora'),
            ],
            'gratora-radius-sm' => [
                'group'   => 'radius',
                'label'   => __('Small corner radius', 'gratora'),
                'default' => '8px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 16,
                'step'    => 1,
                'help'    => __('Buttons, inputs, chips and other controls.', 'gratora'),
            ],
            // Not 'gratora-border-width'. Token names ship inside a block's inline
            // style attribute, and themes select on substrings of it:
            // twentytwentyfive's `html :where([style*="border-width"])` matches
            // the custom property and draws a border on every campaign block.
            // Same for gratora-typeface, gratora-type-size and gratora-button-size: no
            // token name may contain a CSS property a [style*=] selector targets.
            'gratora-stroke' => [
                'group'   => 'radius',
                'label'   => __('Border width', 'gratora'),
                'default' => '1px',
                'control' => 'select',
                'options' => [
                    '1px' => '1px',
                    '2px' => '2px',
                ],
            ],

            'gratora-gap' => [
                'group'   => 'spacing',
                'label'   => __('Block spacing', 'gratora'),
                'default' => '20px',
                'control' => 'range',
                'min'     => 12,
                'max'     => 32,
                'step'    => 2,
                'help'    => __('Vertical rhythm between blocks.', 'gratora'),
            ],
            'gratora-field-gap' => [
                'group'   => 'spacing',
                'label'   => __('Label gap', 'gratora'),
                'default' => '6px',
                'control' => 'range',
                'min'     => 4,
                'max'     => 12,
                'step'    => 1,
            ],

            'gratora-button-size' => [
                'group'   => 'buttons',
                'label'   => __('Button height', 'gratora'),
                'default' => '48px',
                'control' => 'range',
                'min'     => 40,
                'max'     => 60,
                'step'    => 2,
            ],
            'gratora-button-weight' => [
                'group'   => 'buttons',
                'label'   => __('Button text weight', 'gratora'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'gratora'),
                    '600' => __('Semibold', 'gratora'),
                    '700' => __('Bold', 'gratora'),
                ],
            ],
            'gratora-button-shadow' => [
                'group'   => 'buttons',
                'label'   => __('Button shadow', 'gratora'),
                'default' => '0 1px 2px rgba(0,0,0,.08)',
                'control' => 'select',
                'options' => [
                    'none'                                  => __('None', 'gratora'),
                    '0 1px 2px rgba(0,0,0,.08)'             => __('Soft', 'gratora'),
                    '0 6px 16px rgba(0,0,0,.12)'            => __('Strong', 'gratora'),
                ],
            ],
            'gratora-button-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button background', 'gratora'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use the accent color.', 'gratora'),
            ],
            'gratora-button-fg' => [
                'group'   => 'buttons',
                'label'   => __('Button text color', 'gratora'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use white on filled buttons.', 'gratora'),
            ],
            'gratora-button-border' => [
                'group'   => 'buttons',
                'label'   => __('Button border', 'gratora'),
                'default' => '0',
                'control' => 'select',
                'options' => [
                    '0'                       => __('None (filled)', 'gratora'),
                    '1px solid currentColor'  => __('Outline thin', 'gratora'),
                    '2px solid currentColor'  => __('Outline thick', 'gratora'),
                ],
            ],
            'gratora-button-hover-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button hover background', 'gratora'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to inherit the button background.', 'gratora'),
            ],

            'gratora-focus-ring' => [
                'group'   => 'elevation',
                'label'   => __('Focus ring color', 'gratora'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'gratora-card-shadow' => [
                'group'   => 'elevation',
                'label'   => __('Card shadow', 'gratora'),
                'default' => '0 12px 32px rgba(15, 23, 42, .06)',
                'control' => 'select',
                'options' => [
                    'none'                                       => __('None', 'gratora'),
                    '0 1px 2px rgba(15, 23, 42, .04)'            => __('Soft', 'gratora'),
                    '0 12px 32px rgba(15, 23, 42, .06)'          => __('Floating', 'gratora'),
                    '0 30px 60px rgba(0, 0, 0, .25)'             => __('Dramatic', 'gratora'),
                ],
            ],
        ];
    }

    /** @since 1.0.0 */
    public static function groups(): array
    {
        return [
            'brand'      => __('Brand colors', 'gratora'),
            'surface'    => __('Surface', 'gratora'),
            'typography' => __('Typography', 'gratora'),
            'radius'     => __('Radius + borders', 'gratora'),
            'spacing'    => __('Spacing', 'gratora'),
            'buttons'    => __('Buttons', 'gratora'),
            'elevation'  => __('Focus + elevation', 'gratora'),
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
     * What each pass-through token means when no layer set it. Kept out of
     * defaults() so the catalogue still carries none.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public static function inherited(): array
    {
        $out = [];
        foreach (self::PASS_THROUGH as $key => $def) {
            $out[$key] = $def['inherits'];
        }
        return $out;
    }

    /**
     * Validate CSS values to prevent declaration breakout.
     *
     * @since 1.0.0
     */
    /**
     * The same colour in a notation the PDF renderer parses.
     *
     * Dompdf reads 3-, 4-, 6- and 8-digit hex and the rgb()/rgba() functions;
     * hsl() it resolves to nothing, and a page has no ink for transparent or
     * for a keyword that inherits from a box a PDF does not have. sanitize()
     * accepts all of those for the screen, so the receipt has to make its own
     * decision rather than dropping every accent that is not plain hex.
     *
     * An hsl() accent is converted rather than admitted: passing it through
     * would hand the renderer a value it parses to null, so it is rebuilt as
     * the hex of the same colour.
     *
     * @since 1.0.0
     */
    public static function printColor(string $value, string $fallback): string
    {
        $v = trim($value);

        // As tight as sanitiseValue's own classes: a pass-through value lands
        // inside a <style> block, so neither pattern may carry ';', '{' or '}'.
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) === 1) return $v;
        if (preg_match('/^rgba?\(\s*[0-9.,\s%\/-]+\s*\)$/i', $v) === 1) return $v;
        if (preg_match('/^hsla?\(\s*[0-9.,\s%\/-]+\s*\)$/i', $v) === 1) return Ink::hex($v) ?? $fallback;

        return $fallback;
    }

    public static function sanitize(array $tokens): array
    {
        $out = [];
        $catalogue = self::catalogue() + self::PASS_THROUGH;
        foreach ($tokens as $key => $value) {
            if (! isset($catalogue[$key])) continue;
            $val = is_scalar($value) ? trim((string) $value) : '';
            if ($val === '') continue;

            $sanitised = self::sanitiseValue($catalogue[$key], $val);
            if ($sanitised !== null) $out[$key] = $sanitised;
        }
        return $out;
    }

    /** @since 1.0.0 */
    private static function sanitiseValue(array $def, string $value): ?string
    {
        if (preg_match('/[;{}<>\\\\]/', $value)) return null;

        $control = (string) ($def['control'] ?? '');

        switch ($control) {
            case 'color':
                $hex = sanitize_hex_color($value);
                if (is_string($hex) && $hex !== '') return $hex;
                // Accept alpha hex variants rejected by sanitize_hex_color.
                if (preg_match('/^#(?:[0-9a-fA-F]{4}|[0-9a-fA-F]{8})$/', $value)) return strtolower($value);
                if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,\s%\/-]+\s*\)$/i', $value)) return $value;
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
