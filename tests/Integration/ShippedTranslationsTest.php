<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\Pages\DonationsPage;
use PO;
use Translation_Entry;
use WP_Locale_Switcher;

/**
 * German and Spanish ship in languages/. A site in one of them reads the
 * plugin in it with no language pack installed, and what WordPress serves is
 * what the .po source says: the .mo and .json files are generated, so a source
 * edited without regenerating them would ship the old wording.
 */
final class ShippedTranslationsTest extends IntegrationTestCase
{
    private const DOMAIN = 'gratora-donation-platform';

    private ?WP_Locale_Switcher $originalSwitcher = null;

    protected function setUp(): void
    {
        parent::setUp();

        // switch_to_locale() refuses a locale WordPress itself has no files
        // for. A switcher built with these available is a site set to them.
        add_filter('get_available_languages', static fn (array $langs): array => array_merge($langs, ['de_DE', 'es_ES']));
        $this->originalSwitcher        = $GLOBALS['wp_locale_switcher'];
        $GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
        $GLOBALS['wp_locale_switcher']->init();
    }

    protected function tearDown(): void
    {
        while (is_locale_switched()) {
            restore_previous_locale();
        }
        if ($this->originalSwitcher !== null) {
            $GLOBALS['wp_locale_switcher'] = $this->originalSwitcher;
        }
        remove_all_filters('get_available_languages');
        parent::tearDown();
    }

    /** @return array<string, array{0:string}> */
    public function locales(): array
    {
        return ['German' => ['de_DE'], 'Spanish' => ['es_ES']];
    }

    /** @dataProvider locales */
    public function test_a_site_in_a_shipped_language_reads_every_string_as_the_source_has_it(string $locale): void
    {
        $source = $this->source($locale);
        $this->assertTrue(switch_to_locale($locale));

        foreach ($source as $entry) {
            if ($entry->is_plural) {
                $this->assertSame($entry->translations[0], $this->plural($entry, 1), $entry->singular);
                $this->assertSame($entry->translations[1], $this->plural($entry, 2), $entry->plural);
            } else {
                $served = $entry->context === null
                    ? __($entry->singular, self::DOMAIN)
                    : _x($entry->singular, $entry->context, self::DOMAIN);
                $this->assertSame($entry->translations[0], $served, $entry->singular);
            }
        }

        restore_previous_locale();
        $this->assertSame('Donations', __('Donations', self::DOMAIN));
    }

    /** @dataProvider locales */
    public function test_a_screen_finds_its_script_translations_where_wordpress_looks(string $locale): void
    {
        global $wp_scripts;

        set_current_screen('gratora_page_gratora-donations');
        ob_start();
        (new DonationsPage())->render();
        ob_end_clean();

        $bundle = substr($wp_scripts->registered['gratora-admin-donations']->src, strlen(GRATORA_URL));
        $source = $this->source($locale);
        switch_to_locale($locale);

        $messages = $this->served($bundle);

        $this->assertArrayHasKey('Donations', $messages);
        foreach ($messages as $key => $translations) {
            $this->assertSame($source[$key]->translations, $translations, $key);
        }
    }

    /** @dataProvider locales */
    public function test_every_script_file_says_what_the_source_says(string $locale): void
    {
        $source = $this->source($locale);
        $files  = glob(GRATORA_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '-*.json') ?: [];
        $this->assertNotEmpty($files);
        switch_to_locale($locale);

        foreach ($files as $file) {
            $bundle   = (string) json_decode((string) file_get_contents($file), true)['source'];
            $messages = $this->served($bundle);

            $this->assertNotEmpty($messages, $bundle);
            foreach ($messages as $key => $translations) {
                $this->assertArrayHasKey($key, $source, "{$bundle} carries a string the source no longer has: {$key}");
                $this->assertSame($source[$key]->translations, $translations, "{$bundle}: {$key}");
            }
        }
    }

    /**
     * What WordPress hands a script at this path inside an installed copy of
     * the plugin, keyed as the script looks its strings up.
     *
     * @return array<string, list<string>>
     */
    private function served(string $bundle): array
    {
        $handle = 'gratora-shipped-' . md5($bundle);
        wp_register_script($handle, plugins_url('gratora-donation-platform/' . $bundle), [], '1', true);

        $json = load_script_textdomain($handle, self::DOMAIN, GRATORA_DIR . 'languages');
        $this->assertIsString($json, "no translations were found for {$bundle}");

        $messages = json_decode($json, true)['locale_data']['messages'];
        unset($messages['']);

        return $messages;
    }

    private function plural(Translation_Entry $entry, int $count): string
    {
        return $entry->context === null
            ? _n($entry->singular, $entry->plural, $count, self::DOMAIN)
            : _nx($entry->singular, $entry->plural, $count, $entry->context, self::DOMAIN);
    }

    /** @return array<string, Translation_Entry> the entries of the locale's .po, by lookup key */
    private function source(string $locale): array
    {
        require_once ABSPATH . WPINC . '/pomo/po.php';

        $po = new PO();
        $this->assertTrue($po->import_from_file(GRATORA_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.po'));
        $this->assertNotEmpty($po->entries);

        return $po->entries;
    }
}
