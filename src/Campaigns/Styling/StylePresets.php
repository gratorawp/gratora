<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

/**
 * Brand style presets (built-ins + user customs), stored in dono_org_brand as
 * ['presets' => [...], 'default_id' => id]. Built-ins are always present in all();
 * users may edit a built-in's tokens but cannot delete one.
 *
 * @since 1.0.0
 */
final class StylePresets
{
    private const OPTION = 'dono_org_brand';

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
                'name'        => __('Classic', 'dono'),
                'description' => __('Balanced, friendly, accent green. The Dono default.', 'dono'),
                'tokens'      => [
                    // Signature Dono pill donate button. Other presets fall
                    // back to --dono-radius-sm; the Theme preset inherits the
                    // site's button radius via themePreset().
                    'dono-button-radius' => '999px',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'bold',
                'name'        => __('Bold', 'dono'),
                'description' => __('Deep navy with strong typography and a dramatic shadow.', 'dono'),
                'tokens'      => [
                    'dono-accent'         => '#0F3D5C',
                    'dono-accent-soft'    => '#dde6ed',
                    'dono-radius'         => '6px',
                    'dono-radius-sm'      => '4px',
                    'dono-button-weight'  => '700',
                    'dono-button-shadow'  => '0 6px 16px rgba(0,0,0,.12)',
                    'dono-heading-weight' => '700',
                    'dono-card-shadow'    => '0 30px 60px rgba(0, 0, 0, .25)',
                    'dono-focus-ring'     => '#0F3D5C',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'quiet',
                'name'        => __('Quiet', 'dono'),
                'description' => __('Minimal lines and lots of white space. Outlined button, no color, no shadows.', 'dono'),
                'tokens'      => [
                    'dono-accent'          => '#111827',
                    'dono-accent-soft'     => '#f3f4f6',
                    'dono-radius'          => '0px',
                    'dono-radius-sm'       => '0px',
                    'dono-bg-soft'         => '#f9fafb',
                    'dono-heading-weight'  => '500',
                    'dono-button-weight'   => '500',
                    'dono-button-shadow'   => 'none',
                    'dono-card-shadow'     => 'none',
                    'dono-focus-ring'      => '#111827',
                    'dono-gap'             => '28px',
                    'dono-button-bg'       => 'transparent',
                    'dono-button-fg'       => '#111827',
                    'dono-button-border'   => '1px solid currentColor',
                    'dono-button-hover-bg' => '#f3f4f6',
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
            $tokens['dono-accent']     = $accent;
            $tokens['dono-focus-ring'] = $accent;
        }
        if (isset($bySlug['background'])) $tokens['dono-bg']   = $bySlug['background'];
        if (isset($bySlug['foreground'])) $tokens['dono-text'] = $bySlug['foreground'];

        $button = wp_get_global_styles(['elements', 'button']) ?? [];
        if (is_array($button)) {
            $radius = $button['border']['radius'] ?? null;
            if (is_string($radius) && $radius !== '') {
                $tokens['dono-radius-sm'] = $radius;
            }
            $weight = $button['typography']['fontWeight'] ?? null;
            if ($weight !== null && $weight !== '') {
                $tokens['dono-button-weight'] = (string) $weight;
            }
            $btnBg = $button['color']['background'] ?? null;
            if (is_string($btnBg) && $btnBg !== '' && ! isset($tokens['dono-accent'])) {
                $tokens['dono-accent']     = $btnBg;
                $tokens['dono-focus-ring'] = $btnBg;
            }
        }

        if ($tokens === []) return null;

        return [
            'id'          => 'theme',
            'name'        => __('Site theme', 'dono'),
            'description' => __('Picks up accent, background, and button styles from the active WordPress theme (theme.json).', 'dono'),
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
