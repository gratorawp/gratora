<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Styling\StylePresets;
use Gratora\Campaigns\Styling\Tokens;
use WP_Theme_JSON_Resolver;

/**
 * theme.json is not the token catalogue. A font weight of 'inherit' used to
 * reach the donation form, which resolves the var and renders the button at the
 * inherited body weight, and to be dropped by the campaign page, which
 * sanitises. One preset, two buttons on one screen, and neither weight is one
 * the control offers.
 */
final class ThemePresetIsSanitisedTest extends IntegrationTestCase
{
    /** @var callable|null */
    private $filter = null;

    protected function tearDown(): void
    {
        if ($this->filter !== null) {
            remove_filter('wp_theme_json_data_theme', $this->filter, 10);
            $this->filter = null;
        }

        WP_Theme_JSON_Resolver::clean_cached_data();
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $button
     * @return array<string,string>
     */
    private function themeTokens(array $button): array
    {
        $this->filter = static function ($theme) use ($button) {
            return $theme->update_with([
                'version' => 3,
                'styles'  => ['elements' => ['button' => $button]],
            ]);
        };
        add_filter('wp_theme_json_data_theme', $this->filter, 10);
        WP_Theme_JSON_Resolver::clean_cached_data();

        $preset = StylePresets::themePreset();

        return is_array($preset) ? (array) ($preset['tokens'] ?? []) : [];
    }

    public function test_a_weight_the_catalogue_refuses_never_reaches_a_surface(): void
    {
        $tokens = $this->themeTokens([
            'typography' => ['fontWeight' => 'inherit'],
            'color'      => ['background' => '#ffd400'],
        ]);

        $this->assertArrayNotHasKey('gratora-button-weight', $tokens);
        $this->assertSame('#ffd400', $tokens['gratora-accent'] ?? null, 'the readable half still arrives');
    }

    public function test_a_weight_the_catalogue_accepts_still_arrives(): void
    {
        $tokens = $this->themeTokens([
            'typography' => ['fontWeight' => '700'],
            'color'      => ['background' => '#ffd400'],
        ]);

        $this->assertSame('700', $tokens['gratora-button-weight'] ?? null);
    }

    /**
     * Only a palette, replacing the active theme's data so its own palette
     * cannot answer instead.
     *
     * @param array<string,string> $slugs slug => colour, in palette order
     * @return array<string,string>
     */
    private function paletteTokens(array $slugs): array
    {
        $palette = [];
        foreach ($slugs as $slug => $color) {
            $palette[] = ['slug' => $slug, 'name' => $slug, 'color' => $color];
        }

        $this->filter = static fn () => new \WP_Theme_JSON_Data([
            'version'  => 3,
            'settings' => ['color' => ['palette' => $palette]],
        ], 'theme');
        add_filter('wp_theme_json_data_theme', $this->filter, 10);
        WP_Theme_JSON_Resolver::clean_cached_data();

        $preset = StylePresets::themePreset();

        return is_array($preset) ? (array) ($preset['tokens'] ?? []) : [];
    }

    /** @return array<string,array{0:string}> */
    public static function hueSpellings(): array
    {
        return [
            'degrees'  => ['hsl(160deg 60% 80%)'],
            'turns'    => ['hsl(0.4444turn 60% 80%)'],
            'radians'  => ['hsl(2.7925rad 60% 80%)'],
            'gradians' => ['hsl(177.78grad 60% 80%)'],
        ];
    }

    /**
     * A palette may state a hue in any CSS angle unit. Reading only bare
     * degrees dropped the primary and, with nothing else derived, the preset.
     *
     * @dataProvider hueSpellings
     */
    public function test_a_primary_in_any_hue_unit_is_the_accent(string $primary): void
    {
        $tokens = $this->paletteTokens(['primary' => $primary]);

        $this->assertSame($primary, $tokens['gratora-accent'] ?? null);
    }

    public function test_a_primary_nothing_can_read_gives_way_to_the_next_candidate(): void
    {
        $tokens = $this->paletteTokens(['primary' => 'oklch(0.7 0.1 200)', 'accent-1' => '#0f766e']);

        $this->assertSame('#0f766e', $tokens['gratora-accent'] ?? null);
        $this->assertSame('#0f766e', $tokens['gratora-focus-ring'] ?? null);
    }

    /** @return array<string,array{0:string}> */
    public static function malformedPrimaries(): array
    {
        return [
            'a unit apart from its hue' => ['hsl(160 deg 60% 80%)'],
            'a unit and nothing else'   => ['hsl(deg)'],
        ];
    }

    /**
     * An hsl() CSS cannot parse paints nothing, so the next candidate the
     * theme offers is the accent rather than a colour no surface draws.
     *
     * @dataProvider malformedPrimaries
     */
    public function test_a_primary_css_cannot_parse_gives_way_to_the_next_candidate(string $primary): void
    {
        $tokens = $this->paletteTokens(['primary' => $primary, 'accent-1' => '#ffee58']);

        $this->assertSame('#ffee58', $tokens['gratora-accent'] ?? null);
    }

    /** Whatever the active theme happens to supply, both surfaces see the same map. */
    public function test_the_preset_is_what_both_surfaces_would_keep(): void
    {
        $preset = StylePresets::themePreset();
        $tokens = is_array($preset) ? (array) ($preset['tokens'] ?? []) : [];

        $this->assertSame($tokens, Tokens::sanitize($tokens));
    }

    /**
     * The preset is meant to be the theme's or absent. Reading the merged data
     * folded WordPress's own defaults in, so a theme that declares nothing
     * still offered a "Site theme" preset painted in the editor's slate.
     */
    public function test_a_theme_that_declares_nothing_offers_no_preset(): void
    {
        $this->filter = static function ($theme) {
            return $theme->update_with(['version' => 3]);
        };
        add_filter('wp_theme_json_data_theme', static fn () => new \WP_Theme_JSON_Data(['version' => 3], 'theme'), 99);
        WP_Theme_JSON_Resolver::clean_cached_data();

        $preset = StylePresets::themePreset();

        remove_all_filters('wp_theme_json_data_theme');
        WP_Theme_JSON_Resolver::clean_cached_data();

        $this->assertNull($preset);
    }
}
