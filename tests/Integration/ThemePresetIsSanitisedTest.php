<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Styling\StylePresets;
use FundKit\Campaigns\Styling\Tokens;
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

        $this->assertArrayNotHasKey('fundkit-button-weight', $tokens);
        $this->assertSame('#ffd400', $tokens['fundkit-accent'] ?? null, 'the readable half still arrives');
    }

    public function test_a_weight_the_catalogue_accepts_still_arrives(): void
    {
        $tokens = $this->themeTokens([
            'typography' => ['fontWeight' => '700'],
            'color'      => ['background' => '#ffd400'],
        ]);

        $this->assertSame('700', $tokens['fundkit-button-weight'] ?? null);
    }

    /** Whatever the active theme happens to supply, both surfaces see the same map. */
    public function test_the_preset_is_what_both_surfaces_would_keep(): void
    {
        $preset = StylePresets::themePreset();
        $tokens = is_array($preset) ? (array) ($preset['tokens'] ?? []) : [];

        $this->assertSame($tokens, Tokens::sanitize($tokens));
    }
}
