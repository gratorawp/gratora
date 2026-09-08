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
     *   options?: array<array-key,string>,
     *   help?: string
     * }>
     *
     * @since 1.0.0
     */
    /**
     * Tokens a preset may set that are not admin controls. Each is deliberately
     * left out of catalogue(), and so out of defaults(), because unset is what
     * makes it inherit: --fundkit-button-radius falls through to
     * --fundkit-radius-sm, so an org that rounds its controls rounds its button
     * with them. A default here would pin the button and break that, but
     * sanitize() has to keep the key or Classic loses its pill on the first
     * save of the brand panel.
     *
     * @var array<string, array{control: string}>
     */
    private const PASS_THROUGH = [
        'fundkit-button-radius'   => ['control' => 'range'],
        'fundkit-switcher-radius' => ['control' => 'range'],
    ];

    public static function catalogue(): array
    {
        return [
            'fundkit-accent' => [
                'group'   => 'brand',
                'label'   => __('Accent', 'fundraising-toolkit'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'fundkit-accent-soft' => [
                'group'   => 'brand',
                'label'   => __('Accent soft', 'fundraising-toolkit'),
                'default' => '#efedf8',
                'control' => 'color',
                'help'    => __('Translucent variant used for hover and selected tiles.', 'fundraising-toolkit'),
            ],
            'fundkit-text' => [
                'group'   => 'brand',
                'label'   => __('Body text', 'fundraising-toolkit'),
                'default' => '#111827',
                'control' => 'color',
            ],
            'fundkit-text-muted' => [
                'group'   => 'brand',
                'label'   => __('Muted text', 'fundraising-toolkit'),
                'default' => '#6b7280',
                'control' => 'color',
                'help'    => __('Helper text, placeholders, captions.', 'fundraising-toolkit'),
            ],

            'fundkit-bg' => [
                'group'   => 'surface',
                'label'   => __('Background', 'fundraising-toolkit'),
                'default' => '#ffffff',
                'control' => 'color',
                'help'    => __('The card behind the form, and the panels on the campaign page.', 'fundraising-toolkit'),
            ],
            'fundkit-field-bg' => [
                'group'   => 'surface',
                'label'   => __('Field background', 'fundraising-toolkit'),
                'default' => '#ffffff',
                'control' => 'color',
                'help'    => __('Inside the boxes a donor types in or picks from.', 'fundraising-toolkit'),
            ],
            'fundkit-bg-soft' => [
                'group'   => 'surface',
                'label'   => __('Soft background', 'fundraising-toolkit'),
                'default' => '#f8fafb',
                'control' => 'color',
                'help'    => __('Amount tile resting fill.', 'fundraising-toolkit'),
            ],
            'fundkit-border' => [
                'group'   => 'surface',
                'label'   => __('Border', 'fundraising-toolkit'),
                'default' => '#e5e7eb',
                'control' => 'color',
            ],

            'fundkit-typeface' => [
                'group'   => 'typography',
                'label'   => __('Font family', 'fundraising-toolkit'),
                'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'control' => 'font',
            ],
            'fundkit-type-size' => [
                'group'   => 'typography',
                'label'   => __('Base font size', 'fundraising-toolkit'),
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
                'label'   => __('Heading weight', 'fundraising-toolkit'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'fundraising-toolkit'),
                    '600' => __('Semibold', 'fundraising-toolkit'),
                    '700' => __('Bold', 'fundraising-toolkit'),
                ],
            ],
            'fundkit-body-weight' => [
                'group'   => 'typography',
                'label'   => __('Body weight', 'fundraising-toolkit'),
                'default' => '400',
                'control' => 'select',
                'options' => [
                    '400' => __('Regular', 'fundraising-toolkit'),
                    '500' => __('Medium', 'fundraising-toolkit'),
                ],
            ],

            'fundkit-radius' => [
                'group'   => 'radius',
                'label'   => __('Corner radius', 'fundraising-toolkit'),
                'default' => '10px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 24,
                'step'    => 1,
                'help'    => __('Cards, panels and other surfaces.', 'fundraising-toolkit'),
            ],
            'fundkit-radius-sm' => [
                'group'   => 'radius',
                'label'   => __('Small corner radius', 'fundraising-toolkit'),
                'default' => '8px',
                'control' => 'range',
                'min'     => 0,
                'max'     => 16,
                'step'    => 1,
                'help'    => __('Buttons, inputs, chips and other controls.', 'fundraising-toolkit'),
            ],
            // Not 'fundkit-border-width'. Token names ship inside a block's inline
            // style attribute, and themes select on substrings of it:
            // twentytwentyfive's `html :where([style*="border-width"])` matches
            // the custom property and draws a border on every campaign block.
            // Same for fundkit-typeface, fundkit-type-size and fundkit-button-size: no
            // token name may contain a CSS property a [style*=] selector targets.
            'fundkit-stroke' => [
                'group'   => 'radius',
                'label'   => __('Border width', 'fundraising-toolkit'),
                'default' => '1px',
                'control' => 'select',
                'options' => [
                    '1px' => '1px',
                    '2px' => '2px',
                ],
            ],

            'fundkit-gap' => [
                'group'   => 'spacing',
                'label'   => __('Block spacing', 'fundraising-toolkit'),
                'default' => '20px',
                'control' => 'range',
                'min'     => 12,
                'max'     => 32,
                'step'    => 2,
                'help'    => __('Vertical rhythm between blocks.', 'fundraising-toolkit'),
            ],
            'fundkit-field-gap' => [
                'group'   => 'spacing',
                'label'   => __('Label gap', 'fundraising-toolkit'),
                'default' => '6px',
                'control' => 'range',
                'min'     => 4,
                'max'     => 12,
                'step'    => 1,
            ],

            'fundkit-button-size' => [
                'group'   => 'buttons',
                'label'   => __('Button height', 'fundraising-toolkit'),
                'default' => '48px',
                'control' => 'range',
                'min'     => 40,
                'max'     => 60,
                'step'    => 2,
            ],
            'fundkit-button-weight' => [
                'group'   => 'buttons',
                'label'   => __('Button text weight', 'fundraising-toolkit'),
                'default' => '600',
                'control' => 'select',
                'options' => [
                    '500' => __('Medium', 'fundraising-toolkit'),
                    '600' => __('Semibold', 'fundraising-toolkit'),
                    '700' => __('Bold', 'fundraising-toolkit'),
                ],
            ],
            'fundkit-button-shadow' => [
                'group'   => 'buttons',
                'label'   => __('Button shadow', 'fundraising-toolkit'),
                'default' => '0 1px 2px rgba(0,0,0,.08)',
                'control' => 'select',
                'options' => [
                    'none'                                  => __('None', 'fundraising-toolkit'),
                    '0 1px 2px rgba(0,0,0,.08)'             => __('Soft', 'fundraising-toolkit'),
                    '0 6px 16px rgba(0,0,0,.12)'            => __('Strong', 'fundraising-toolkit'),
                ],
            ],
            'fundkit-button-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button background', 'fundraising-toolkit'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use the accent color.', 'fundraising-toolkit'),
            ],
            'fundkit-button-fg' => [
                'group'   => 'buttons',
                'label'   => __('Button text color', 'fundraising-toolkit'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to use white on filled buttons.', 'fundraising-toolkit'),
            ],
            'fundkit-button-border' => [
                'group'   => 'buttons',
                'label'   => __('Button border', 'fundraising-toolkit'),
                'default' => '0',
                'control' => 'select',
                'options' => [
                    '0'                       => __('None (filled)', 'fundraising-toolkit'),
                    '1px solid currentColor'  => __('Outline thin', 'fundraising-toolkit'),
                    '2px solid currentColor'  => __('Outline thick', 'fundraising-toolkit'),
                ],
            ],
            'fundkit-button-hover-bg' => [
                'group'   => 'buttons',
                'label'   => __('Button hover background', 'fundraising-toolkit'),
                'default' => '',
                'control' => 'color',
                'help'    => __('Leave empty to inherit the button background.', 'fundraising-toolkit'),
            ],

            'fundkit-focus-ring' => [
                'group'   => 'elevation',
                'label'   => __('Focus ring color', 'fundraising-toolkit'),
                'default' => '#211d3f',
                'control' => 'color',
            ],
            'fundkit-card-shadow' => [
                'group'   => 'elevation',
                'label'   => __('Card shadow', 'fundraising-toolkit'),
                'default' => '0 12px 32px rgba(15, 23, 42, .06)',
                'control' => 'select',
                'options' => [
                    'none'                                       => __('None', 'fundraising-toolkit'),
                    '0 1px 2px rgba(15, 23, 42, .04)'            => __('Soft', 'fundraising-toolkit'),
                    '0 12px 32px rgba(15, 23, 42, .06)'          => __('Floating', 'fundraising-toolkit'),
                    '0 30px 60px rgba(0, 0, 0, .25)'             => __('Dramatic', 'fundraising-toolkit'),
                ],
            ],
        ];
    }

    /** @since 1.0.0 */
    public static function groups(): array
    {
        return [
            'brand'      => __('Brand colors', 'fundraising-toolkit'),
            'surface'    => __('Surface', 'fundraising-toolkit'),
            'typography' => __('Typography', 'fundraising-toolkit'),
            'radius'     => __('Radius + borders', 'fundraising-toolkit'),
            'spacing'    => __('Spacing', 'fundraising-toolkit'),
            'buttons'    => __('Buttons', 'fundraising-toolkit'),
            'elevation'  => __('Focus + elevation', 'fundraising-toolkit'),
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
     * Validate CSS values to prevent declaration breakout.
     *
     * @since 1.0.0
     */
    /**
     * The same colour in a notation the PDF renderer parses.
     *
     * Dompdf reads 3-, 4-, 6- and 8-digit hex and the rgb()/rgba() functions;
     * it has no hsl(), and a page has no ink for transparent or for a keyword
     * that inherits from a box a PDF does not have. sanitize() accepts all of
     * those for the screen, so the receipt has to make its own decision rather
     * than dropping every accent that is not plain hex.
     *
     * @since 1.0.0
     */
    public static function printColor(string $value, string $fallback): string
    {
        $v = trim($value);

        // As tight as sanitiseValue's own classes: themePreset bypasses
        // sanitize entirely and the value lands inside a <style> block, so
        // neither pattern may carry ';', '{' or '}'.
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) === 1) return $v;
        if (preg_match('/^rgba?\(\s*[0-9.,\s%\/-]+\s*\)$/i', $v) === 1) return $v;

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
