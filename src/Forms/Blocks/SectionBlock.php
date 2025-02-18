<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

/**
 * Styled content container block.
 *
 * @version 1.0.0
 */
final class SectionBlock implements Block
{
    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/section';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'background' => ['type' => 'string', 'default' => ''],
            'textColor'  => ['type' => 'string', 'default' => ''],
            'border'     => ['type' => 'object', 'default' => ['color' => '', 'width' => 0, 'style' => 'solid', 'radius' => 0]],
            'shadow'     => ['type' => 'string', 'default' => ''],
            'padding'    => ['type' => 'object', 'default' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
            'margin'     => ['type' => 'object', 'default' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
            'minHeight'  => ['type' => 'number', 'default' => 0],
        ];
    }

    /**
     * Renders the section container with computed inline styles.
     */
    public function render(array $attrs, string $content): string
    {
        $style = self::sectionStyle($attrs);
        return sprintf(
            '<div class="dono-block dono-block--section"%s>%s</div>',
            $style !== '' ? ' style="' . esc_attr($style) . '"' : '',
            $content
        );
    }

    /**
     * Strings sanitised at source; safecss_filter_attr() is the final gate.
     *
     * @param array<string,mixed> $attrs
     */
    public static function sectionStyle(array $attrs): string
    {
        $border    = is_array($attrs['border']  ?? null) ? $attrs['border']  : [];
        $padding   = is_array($attrs['padding'] ?? null) ? $attrs['padding'] : [];
        $margin    = is_array($attrs['margin']  ?? null) ? $attrs['margin']  : [];
        $minHeight = isset($attrs['minHeight']) ? (float) $attrs['minHeight'] : 0.0;

        $rules = [];

        $bg = self::safeColor($attrs['background'] ?? '');
        if ($bg !== '') $rules[] = 'background-color:' . $bg;

        $tc = self::safeColor($attrs['textColor'] ?? '');
        if ($tc !== '') $rules[] = 'color:' . $tc;

        $bw = (float) ($border['width'] ?? 0);
        if ($bw > 0) {
            $rules[] = 'border-width:' . $bw . 'px';
            $rules[] = 'border-style:' . self::safeBorderStyle($border['style'] ?? 'solid');
            $bc = self::safeColor($border['color'] ?? '');
            if ($bc !== '') $rules[] = 'border-color:' . $bc;
        }
        $br = (float) ($border['radius'] ?? 0);
        if ($br > 0) $rules[] = 'border-radius:' . $br . 'px';

        $shadow = self::safeShadow($attrs['shadow'] ?? '');
        if ($shadow !== '') $rules[] = 'box-shadow:' . $shadow;

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $v = (float) ($padding[$side] ?? 0);
            if ($v > 0) $rules[] = 'padding-' . $side . ':' . $v . 'px';

            $m = (float) ($margin[$side] ?? 0);
            if ($m > 0) $rules[] = 'margin-' . $side . ':' . $m . 'px';
        }

        if ($minHeight > 0) $rules[] = 'min-height:' . $minHeight . 'px';

        return safecss_filter_attr(implode(';', $rules));
    }

    /**
     * Validates and returns a safe CSS color value.
     */
    private static function safeColor(mixed $raw): string
    {
        if (! is_string($raw)) return '';
        $v = trim($raw);
        if ($v === '') return '';

        $hex = sanitize_hex_color($v);
        if (is_string($hex) && $hex !== '') return $hex;

        // Allow #rgba / #rrggbbaa (sanitize_hex_color rejects alpha).
        if (preg_match('/^#(?:[0-9a-fA-F]{4}|[0-9a-fA-F]{8})$/', $v)) {
            return strtolower($v);
        }

        if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,\s%\/-]+\s*\)$/i', $v)) {
            return $v;
        }

        return '';
    }

    /**
     * Returns a valid CSS border-style keyword.
     */
    private static function safeBorderStyle(mixed $raw): string
    {
        $allowed = ['none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'];
        $v = is_string($raw) ? strtolower(trim($raw)) : '';
        return in_array($v, $allowed, true) ? $v : 'solid';
    }

    /**
     * Validates a box-shadow value; rejects strings with CSS-breaking characters.
     */
    private static function safeShadow(mixed $raw): string
    {
        if (! is_string($raw)) return '';
        $v = trim($raw);
        if ($v === '') return '';
        // Reject chars that could break out of the declaration.
        if (preg_match('/[;{}<>\\\\]/', $v)) return '';
        return $v;
    }
}
