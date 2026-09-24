<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Styling;

/**
 * Brand style presets (built-ins + user customs), stored in gratora_org_brand as
 * ['presets' => [...], 'default_id' => id]. Built-ins are always present in all();
 * users may edit a built-in's tokens but cannot delete one.
 *
 * @since 1.0.0
 */
use WP_Theme_JSON_Resolver;

final class StylePresets
{
    private const OPTION = 'gratora_org_brand';

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
                'name'        => __('Classic', 'gratora-donation-platform'),
                'description' => __('Balanced, friendly, accent green. The Gratora default.', 'gratora-donation-platform'),
                'tokens'      => [
                    // Signature Gratora pill donate button. Other presets fall
                    // back to --gratora-radius-sm; the Theme preset inherits the
                    // site's button radius via themePreset().
                    'gratora-button-radius' => '999px',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'bold',
                'name'        => __('Bold', 'gratora-donation-platform'),
                'description' => __('Deep navy with strong typography and a dramatic shadow.', 'gratora-donation-platform'),
                'tokens'      => [
                    'gratora-accent'         => '#0F3D5C',
                    'gratora-accent-soft'    => '#dde6ed',
                    'gratora-radius'         => '6px',
                    'gratora-radius-sm'      => '4px',
                    'gratora-button-weight'  => '700',
                    'gratora-button-shadow'  => '0 6px 16px rgba(0,0,0,.12)',
                    'gratora-heading-weight' => '700',
                    'gratora-card-shadow'    => '0 30px 60px rgba(0, 0, 0, .25)',
                    'gratora-focus-ring'     => '#0F3D5C',
                ],
                'builtin'     => true,
            ],
            [
                'id'          => 'quiet',
                'name'        => __('Quiet', 'gratora-donation-platform'),
                'description' => __('Minimal lines and lots of white space. Outlined button, no color, no shadows.', 'gratora-donation-platform'),
                'tokens'      => [
                    'gratora-accent'          => '#111827',
                    'gratora-accent-soft'     => '#f3f4f6',
                    'gratora-radius'          => '0px',
                    'gratora-radius-sm'       => '0px',
                    'gratora-bg-soft'         => '#f9fafb',
                    'gratora-heading-weight'  => '500',
                    'gratora-button-weight'   => '500',
                    'gratora-button-shadow'   => 'none',
                    'gratora-card-shadow'     => 'none',
                    'gratora-focus-ring'      => '#111827',
                    'gratora-gap'             => '28px',
                    'gratora-button-bg'       => 'transparent',
                    'gratora-button-fg'       => '#111827',
                    'gratora-button-border'   => '1px solid currentColor',
                    'gratora-button-hover-bg' => '#f3f4f6',
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
                // Preserve user edits to built-ins.
                $merged['builtin'] = true;
                // Resolve built-in labels through translation on each read.
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
        foreach ($savedById as $p) {
            $p['builtin'] = false;
            // Fall back to the custom preset ID when unnamed.
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

        // The theme's own layer, not the merged one: wp_get_global_settings and
        // wp_get_global_styles fold WordPress's defaults in, so a classic theme
        // that declares nothing still "derives" core's editor slate and a font
        // weight of 'inherit'. The preset is meant to be the theme's or absent.
        $raw = WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();

        $palette = (array) ($raw['settings']['color']['palette'] ?? []);
        $colors  = isset($palette[0]) ? $palette : ($palette['theme'] ?? []);
        $bySlug  = [];
        foreach ((array) $colors as $entry) {
            if (is_array($entry) && isset($entry['slug'], $entry['color'])) {
                $bySlug[(string) $entry['slug']] = (string) $entry['color'];
            }
        }
        // The first candidate the catalogue can read: a primary in a notation
        // it refuses gives way to the next instead of leaving no accent.
        $candidates = [$bySlug['primary'] ?? null, $bySlug['accent'] ?? null, $bySlug['accent-1'] ?? null, $colors[0]['color'] ?? null];
        foreach ($candidates as $accent) {
            if (is_string($accent) && Tokens::sanitize(['gratora-accent' => $accent]) !== []) {
                $tokens['gratora-accent']     = $accent;
                $tokens['gratora-focus-ring'] = $accent;
                break;
            }
        }
        if (isset($bySlug['background'])) $tokens['gratora-bg']   = $bySlug['background'];
        if (isset($bySlug['foreground'])) $tokens['gratora-text'] = $bySlug['foreground'];

        $button = (array) ($raw['styles']['elements']['button'] ?? []);
        $radius = $button['border']['radius'] ?? null;
        if (is_string($radius) && $radius !== '') {
            $tokens['gratora-radius-sm'] = $radius;
        }
        $weight = $button['typography']['fontWeight'] ?? null;
        if ($weight !== null && $weight !== '') {
            $tokens['gratora-button-weight'] = (string) $weight;
        }
        $btnBg = $button['color']['background'] ?? null;
        if (is_string($btnBg) && $btnBg !== '' && ! isset($tokens['gratora-accent'])) {
            $tokens['gratora-accent']     = $btnBg;
            $tokens['gratora-focus-ring'] = $btnBg;
        }

        // theme.json is not the token catalogue: it yields values like the
        // keyword 'inherit' for a font weight, which one surface honours and
        // another drops, so one preset renders two different buttons.
        $tokens = Tokens::sanitize($tokens);

        if ($tokens === []) return null;

        return [
            'id'          => 'theme',
            'name'        => __('Site theme', 'gratora-donation-platform'),
            'description' => __('Picks up accent, background, and button styles from the active WordPress theme (theme.json).', 'gratora-donation-platform'),
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
     * The preset's tokens as the layers that produced them: what ships, then
     * what the org changed. A pairing is a statement one layer made, and the
     * flat map cannot say which layer made it.
     *
     * @return array<int, array<string,string>>
     *
     * @since 1.0.0
     */
    public static function tokenLayers(string $id): array
    {
        $p = self::find($id);
        if (! is_array($p['tokens'] ?? null)) {
            return [self::tokensFor($id)];
        }

        foreach (self::builtinsWithTheme() as $b) {
            if (($b['id'] ?? null) !== $id) {
                continue;
            }
            $shipped = is_array($b['tokens'] ?? null) ? $b['tokens'] : [];
            $edits   = array_diff_assoc($p['tokens'], $shipped);

            return $edits === [] ? [$p['tokens']] : [$shipped, $edits];
        }

        return [$p['tokens']];
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

    /** @since 1.0.0 */
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
