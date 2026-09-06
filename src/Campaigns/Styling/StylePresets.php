<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Styling;

/**
 * Brand style presets (built-ins + user customs), stored in fundkit_org_brand as
 * ['presets' => [...], 'default_id' => id]. Built-ins are always present in all();
 * users may edit a built-in's tokens but cannot delete one.
 *
 * @since 1.0.0
 */
final class StylePresets
{
    private const OPTION = 'fundkit_org_brand';

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
                'name'        => __('Classic', 'fundraising-toolkit'),
                'description' => __('Balanced, friendly, accent green. The Fundraising Toolkit default.', 'fundraising-toolkit'),
                'tokens'      => [
                    // Signature FundKit pill donate button. Other presets fall
                    // back to --fundkit-radius-sm; the Theme preset inherits the
                    // site's button radius via themePreset().
                    'fundkit-button-radius' => '999px',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'bold',
                'name'        => __('Bold', 'fundraising-toolkit'),
                'description' => __('Deep navy with strong typography and a dramatic shadow.', 'fundraising-toolkit'),
                'tokens'      => [
                    'fundkit-accent'         => '#0F3D5C',
                    'fundkit-accent-soft'    => '#dde6ed',
                    'fundkit-radius'         => '6px',
                    'fundkit-radius-sm'      => '4px',
                    'fundkit-button-weight'  => '700',
                    'fundkit-button-shadow'  => '0 6px 16px rgba(0,0,0,.12)',
                    'fundkit-heading-weight' => '700',
                    'fundkit-card-shadow'    => '0 30px 60px rgba(0, 0, 0, .25)',
                    'fundkit-focus-ring'     => '#0F3D5C',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'quiet',
                'name'        => __('Quiet', 'fundraising-toolkit'),
                'description' => __('Minimal lines and lots of white space. Outlined button, no color, no shadows.', 'fundraising-toolkit'),
                'tokens'      => [
                    'fundkit-accent'          => '#111827',
                    'fundkit-accent-soft'     => '#f3f4f6',
                    'fundkit-radius'          => '0px',
                    'fundkit-radius-sm'       => '0px',
                    'fundkit-bg-soft'         => '#f9fafb',
                    'fundkit-heading-weight'  => '500',
                    'fundkit-button-weight'   => '500',
                    'fundkit-button-shadow'   => 'none',
                    'fundkit-card-shadow'     => 'none',
                    'fundkit-focus-ring'      => '#111827',
                    'fundkit-gap'             => '28px',
                    'fundkit-button-bg'       => 'transparent',
                    'fundkit-button-fg'       => '#111827',
                    'fundkit-button-border'   => '1px solid currentColor',
                    'fundkit-button-hover-bg' => '#f3f4f6',
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
    /**
     * The shipped presets plus the one derived from the active theme, which is
     * the set the Brand panel is seeded from.
     *
     * @return array<int, array<string,mixed>>
     *
     * @since 1.0.0
     */
    public static function builtinsWithTheme(): array
    {
        $out   = self::builtins();
        $theme = self::themePreset();
        if ($theme) $out[] = $theme;

        return $out;
    }

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
                // A built-in's label is a __() call, so it is never stored: an
                // empty one here means "whatever this reader's locale calls it".
                $merged['name'] = $merged['name'] !== '' ? $merged['name'] : $b['name'];
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
            // A custom has no shipped label to fall back on, so its id is the
            // one thing that identifies it in a picker.
            if ($p['name'] === '') $p['name'] = $p['id'];
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
            $tokens['fundkit-accent']     = $accent;
            $tokens['fundkit-focus-ring'] = $accent;
        }
        if (isset($bySlug['background'])) $tokens['fundkit-bg']   = $bySlug['background'];
        if (isset($bySlug['foreground'])) $tokens['fundkit-text'] = $bySlug['foreground'];

        $button = wp_get_global_styles(['elements', 'button']) ?? [];
        if (is_array($button)) {
            $radius = $button['border']['radius'] ?? null;
            if (is_string($radius) && $radius !== '') {
                $tokens['fundkit-radius-sm'] = $radius;
            }
            $weight = $button['typography']['fontWeight'] ?? null;
            if ($weight !== null && $weight !== '') {
                $tokens['fundkit-button-weight'] = (string) $weight;
            }
            $btnBg = $button['color']['background'] ?? null;
            if (is_string($btnBg) && $btnBg !== '' && ! isset($tokens['fundkit-accent'])) {
                $tokens['fundkit-accent']     = $btnBg;
                $tokens['fundkit-focus-ring'] = $btnBg;
            }
        }

        if ($tokens === []) return null;

        return [
            'id'          => 'theme',
            'name'        => __('Site theme', 'fundraising-toolkit'),
            'description' => __('Picks up accent, background, and button styles from the active WordPress theme (theme.json).', 'fundraising-toolkit'),
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
        if (is_array($p['tokens'] ?? null)) {
            return $p['tokens'];
        }

        // An id nothing answers to is a preset that was deleted while forms and
        // campaigns still pointed at it. Returning nothing dropped them to the
        // bare catalogue defaults, which is not a look the org ever chose, and
        // on the form path it also discarded the campaign's own overrides.
        // The org default is the nearest thing to what they had.
        $fallback = self::find(self::defaultId());

        return is_array($fallback['tokens'] ?? null) ? $fallback['tokens'] : [];
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
            'name'        => is_string($p['name'] ?? null) ? trim($p['name']) : '',
            'description' => is_string($p['description'] ?? null) ? (string) $p['description'] : '',
            'tokens'      => Tokens::sanitize(is_array($p['tokens'] ?? null) ? $p['tokens'] : []),
        ];
    }
}
