<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Styling\StylePresets;
use FundKit\Foundation\Plugin;
use FundKit\Settings\SettingsService;

/**
 * A built-in preset's name and description are __() calls, so they read in
 * whoever is looking. Storing the rendered pair, which is what saving the panel
 * does, pins them to the locale of the admin who happened to press Save.
 */
final class BrandPresetLabelsTest extends IntegrationTestCase
{
    private bool $french = false;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option('fundkit_org_brand');

        add_filter('gettext', function ($translated, $text, $domain) {
            if (! $this->french || $domain !== 'fundraising-toolkit') return $translated;

            return $text === 'Classic' ? 'Classique' : $translated;
        }, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_all_filters('gettext');
        delete_option('fundkit_org_brand');
        parent::tearDown();
    }

    private function classic(): array
    {
        foreach (StylePresets::all() as $preset) {
            if (($preset['id'] ?? '') === 'classic') return $preset;
        }

        $this->fail('no classic preset');
    }

    public function test_an_edited_builtin_still_reads_in_the_readers_language(): void
    {
        $edited = $this->classic();
        $edited['tokens']['fundkit-accent'] = '#123456';

        Plugin::instance()->container->get(SettingsService::class)
            ->update('org-brand', ['presets' => [$edited], 'default_id' => 'classic']);

        $this->french = true;
        $read = $this->classic();

        $this->assertSame('Classique', (string) $read['name']);
        $this->assertSame('#123456', (string) ($read['tokens']['fundkit-accent'] ?? ''), 'the edit itself has to survive');
    }

    public function test_a_name_the_admin_typed_is_kept(): void
    {
        $edited         = $this->classic();
        $edited['name'] = 'House style';

        Plugin::instance()->container->get(SettingsService::class)
            ->update('org-brand', ['presets' => [$edited], 'default_id' => 'classic']);

        $this->french = true;

        $this->assertSame('House style', (string) $this->classic()['name']);
    }

    /** A custom has no shipped label behind it, so it must not go nameless. */
    public function test_a_custom_preset_saved_with_no_name_is_still_identifiable(): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('org-brand', [
            'presets' => [[
                'id'          => 'house',
                'name'        => '   ',
                'description' => '',
                'tokens'      => ['fundkit-accent' => '#abcdef'],
            ]],
        ]);

        $names = [];
        foreach (StylePresets::all() as $preset) {
            $names[(string) $preset['id']] = (string) $preset['name'];
        }

        $this->assertSame('house', $names['house'] ?? '');
    }
}
