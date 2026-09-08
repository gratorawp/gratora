<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Styling\StylePresets;
use FundKit\Settings\SettingsService;
use FundKit\Foundation\Plugin;
use WP_Theme_JSON_Resolver;

/**
 * "Site theme" is derived from theme.json on every read. Storing the whole
 * token map on the first edit froze it: the org nudged one colour and the
 * preset stopped following their theme for good, while the panel went on saying
 * it tracks it.
 */
final class DerivedPresetKeepsTrackingTest extends IntegrationTestCase
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

    private function themeAccent(string $hex): void
    {
        if ($this->filter !== null) {
            remove_filter('wp_theme_json_data_theme', $this->filter, 10);
        }

        $this->filter = static function ($theme) use ($hex) {
            return $theme->update_with([
                'version'  => 3,
                'settings' => ['color' => ['palette' => [
                    ['slug' => 'primary', 'color' => $hex, 'name' => 'Primary'],
                ]]],
            ]);
        };
        add_filter('wp_theme_json_data_theme', $this->filter, 10);
        WP_Theme_JSON_Resolver::clean_cached_data();
    }

    private function tokensOfTheme(): array
    {
        foreach (StylePresets::all() as $p) {
            if (($p['id'] ?? '') === 'theme') {
                return (array) ($p['tokens'] ?? []);
            }
        }

        return [];
    }

    public function test_an_edited_derived_preset_still_follows_the_theme(): void
    {
        $this->themeAccent('#111111');

        // The admin nudges one colour and leaves the rest alone.
        $edited = $this->tokensOfTheme();
        $edited['fundkit-bg'] = '#fafafa';

        Plugin::instance()->container->get(SettingsService::class)->update('org-brand', [
            'presets'    => [['id' => 'theme', 'name' => 'Site theme', 'tokens' => $edited]],
            'default_id' => 'theme',
        ]);

        // Months later the theme's palette changes.
        $this->themeAccent('#c62828');

        $now = $this->tokensOfTheme();

        $this->assertSame('#c62828', $now['fundkit-accent'] ?? null, 'the untouched key still follows the theme');
        $this->assertSame('#fafafa', $now['fundkit-bg'] ?? null, 'and the edited one is still the admin\'s');
    }
}
