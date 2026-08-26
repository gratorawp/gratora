<?php

declare(strict_types=1);

namespace GiveFlow\Campaigns\Styling;

/**
 * Brand style presets (built-ins + user customs), stored in giveflow_org_brand as
 * ['presets' => [...], 'default_id' => id]. Built-ins are always present in all();
 * users may edit a built-in's tokens but cannot delete one.
 *
 * @since 1.0.0
 */
final class StylePresets
{
    private const OPTION = 'giveflow_org_brand';

    /**
     * @return array<int, array{
     *   id: string,
     *   name: string,
     *   description?: string,
     *   tokens: array<string,string>,
     *   builtin?: bool
     * }>
     *
     * @since 1.0.0
     */
    public static function builtins(): array
    {
        return [
            [
                'id'          => 'classic',
                'name'        => __('Classic', 'giveflow-fundraising-campaigns'),
                'description' => __('Balanced, friendly, accent green. The GiveFlow default.', 'giveflow-fundraising-campaigns'),
                'tokens'      => [
                    // Signature GiveFlow pill donate button. Other presets fall
                    // back to --giveflow-radius-sm; the Theme preset inherits the
                    // site's button radius via themePreset().
                    'giveflow-button-radius' => '999px',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'bold',
                'name'        => __('Bold', 'giveflow-fundraising-campaigns'),
                'description' => __('Deep navy with strong typography and a dramatic shadow.', 'giveflow-fundraising-campaigns'),
                'tokens'      => [
                    'giveflow-accent'         => '#0F3D5C',
                    'giveflow-accent-soft'    => '#dde6ed',
                    'giveflow-radius'         => '6px',
                    'giveflow-radius-sm'      => '4px',
                    'giveflow-button-weight'  => '700',
                    'giveflow-button-shadow'  => '0 6px 16px rgba(0,0,0,.12)',
                    'giveflow-heading-weight' => '700',
                    'giveflow-card-shadow'    => '0 30px 60px rgba(0, 0, 0, .25)',
                    'giveflow-focus-ring'     => '#0F3D5C',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'quiet',
                'name'        => __('Quiet', 'giveflow-fundraising-campaigns'),
                'description' => __('Minimal lines and lots of white space. Outlined button, no color, no shadows.', 'giveflow-fundraising-campaigns'),
                'tokens'      => [
                    'giveflow-accent'          => '#111827',
                    'giveflow-accent-soft'     => '#f3f4f6',
                    'giveflow-radius'          => '0px',
                    'giveflow-radius-sm'       => '0px',
                    'giveflow-bg-soft'         => '#f9fafb',
                    'giveflow-heading-weight'  => '500',
                    'giveflow-button-weight'   => '500',
                    'giveflow-button-shadow'   => 'none',
                    'giveflow-card-shadow'     => 'none',
                    'giveflow-focus-ring'      => '#111827',
                    'giveflow-gap'             => '28px',
                    'giveflow-button-bg'       => 'transparent',
                    'giveflow-button-fg'       => '#111827',
                    'giveflow-button-border'   => '1px solid currentColor',
                    'giveflow-button-hover-bg' => '#f3f4f6',
                ],
                'builtin'     => true,
            ],
        ];
    }

    /**
     * The full preset list: built-ins (always present, with user edits applied)
     * followed by user-created custom presets.
     *
     * @return array<int, array{id:string, name:string, description?:string, tokens:array<string,string>, builtin?:bool}>
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        $option = get_option(self::OPTION, []);
        $saved  = is_array($option) && is_array($option['presets'] ?? null) ? $option['presets'] : [];

        $savedById = [];
        foreach ($saved as $p) {
            if (is_array($p) && is_string($p['id'] ?? null) && $p['id'] !== '') {
                $savedById[$p['id']] = self::normalise($p);
            }
        }

        $out      = [];
        $builtins = self::builtins();
        $theme    = self::themePreset();
        if ($theme) $builtins[] = $theme;

        foreach ($builtins as $b) {
            if (isset($savedById[$b['id']])) {
                $saved  = $savedById[$b['id']];
                $merged = $saved;
                // Built-in flag is authoritative; preserve user-edited name + tokens.
                $merged['builtin'] = true;
                // Built-in tokens form the baseline; user-saved tokens override
                // individual keys. Stops Tokens::sanitize() from silently
                // erasing built-in tokens that aren't in the catalogue (e.g.
                // Quiet's outlined-button overrides) the first time the user
                // saves anything in the brand panel.
                $merged['tokens'] = array_merge(
                    is_array($b['tokens'] ?? null)     ? $b['tokens']     : [],
                    is_array($saved['tokens'] ?? null) ? $saved['tokens'] : []
                );
                if (isset($b['source']))      $merged['source']      = $b['source'];
                if (isset($b['description'])) $merged['description'] = $merged['description'] ?: $b['description'];
                $out[] = $merged;
                unset($savedById[$b['id']]);
            } else {
                $out[] = $b;
            }
        }
        // Append remaining customs in their saved order.
        foreach ($savedById as $p) {
            $p['builtin'] = false;
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Derive a preset from the active theme's theme.json palette + button styles.
     * Returns null when the theme exposes nothing useful.
     *
     * @since 1.0.0
     */
    public static function themePreset(): ?array
    {
        $tokens = [];

        $palette = (array) (wp_get_global_settings(['color', 'palette']) ?? []);
        // Flat list in modern WP; older WP returned ['theme' => [...]].
        $colors  = isset($palette[0]) ? $palette : ($palette['theme'] ?? []);
        $bySlug  = [];
        foreach ((array) $colors as $entry) {
            if (is_array($entry) && isset($entry['slug'], $entry['color'])) {
                $bySlug[(string) $entry['slug']] = (string) $entry['color'];
            }
        }
        $accent = $bySlug['primary']
            ?? $bySlug['accent']
            ?? $bySlug['accent-1']
            ?? ($colors[0]['color'] ?? null);
        if (is_string($accent) && $accent !== '') {
            $tokens['giveflow-accent']     = $accent;
            $tokens['giveflow-focus-ring'] = $accent;
        }
        if (isset($bySlug['background'])) $tokens['giveflow-bg']   = $bySlug['background'];
        if (isset($bySlug['foreground'])) $tokens['giveflow-text'] = $bySlug['foreground'];

        $button = wp_get_global_styles(['elements', 'button']) ?? [];
        if (is_array($button)) {
            $radius = $button['border']['radius'] ?? null;
            if (is_string($radius) && $radius !== '') {
                $tokens['giveflow-radius-sm'] = $radius;
            }
            $weight = $button['typography']['fontWeight'] ?? null;
            if ($weight !== null && $weight !== '') {
                $tokens['giveflow-button-weight'] = (string) $weight;
            }
            $btnBg = $button['color']['background'] ?? null;
            if (is_string($btnBg) && $btnBg !== '' && ! isset($tokens['giveflow-accent'])) {
                $tokens['giveflow-accent']     = $btnBg;
                $tokens['giveflow-focus-ring'] = $btnBg;
            }
        }

        if ($tokens === []) return null;

        return [
            'id'          => 'theme',
            'name'        => __('Site theme', 'giveflow-fundraising-campaigns'),
            'description' => __('Picks up accent, background, and button styles from the active WordPress theme (theme.json).', 'giveflow-fundraising-campaigns'),
            'tokens'      => $tokens,
            'builtin'     => true,
            'source'      => 'theme',
        ];
    }

    /** @since 1.0.0 */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }

    /**
     * Tokens for a preset id, or empty if unknown.
     *
     * @since 1.0.0
     */
    public static function tokensFor(string $id): array
    {
        $p = self::find($id);
        return is_array($p['tokens'] ?? null) ? $p['tokens'] : [];
    }

    /**
     * Default preset id (the one new campaigns/forms inherit when nothing is
     * explicitly chosen). Falls back to the first built-in.
     *
     * @since 1.0.0
     */
    public static function defaultId(): string
    {
        $option = get_option(self::OPTION, []);
        $id = is_array($option) ? (string) ($option['default_id'] ?? '') : '';
        if ($id !== '' && self::find($id)) return $id;
        $first = self::builtins()[0] ?? null;
        return $first ? (string) $first['id'] : 'classic';
    }

    /**
     * Coerce one preset record into a normalized shape.
     *
     * @since 1.0.0
     */
    private static function normalise(array $p): array
    {
        return [
            'id'          => (string) ($p['id'] ?? ''),
            'name'        => (string) ($p['name'] ?? $p['id'] ?? ''),
            'description' => is_string($p['description'] ?? null) ? (string) $p['description'] : '',
            'tokens'      => Tokens::sanitize(is_array($p['tokens'] ?? null) ? $p['tokens'] : []),
        ];
    }
}
