<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Settings\SettingsService;
use Gratora\Foundation\Plugin;

/**
 * Swedish, Norwegian, Polish, Czech and South African money groups thousands
 * with a space. The settings write ran every string through
 * sanitize_textarea_field(), which trims, so " " arrived as "" and the screen
 * came back reporting no separator at all. The one format those locales need
 * was the one that could not be saved.
 */
final class SeparatorWhitespaceSurvivesSaveTest extends IntegrationTestCase
{
    private function saveFormat(array $format): array
    {
        $request = new \WP_REST_Request('POST', '/gratora/v1/admin/settings/currency-locale');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['format' => $format]));

        $response = rest_do_request($request);

        $this->assertLessThan(400, $response->get_status(), 'the save was refused');

        $saved = (new SettingsService())->get('currency-locale');
        return is_array($saved['format'] ?? null) ? $saved['format'] : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        do_action('rest_api_init');
    }

    public function test_a_space_survives_as_the_thousands_separator(): void
    {
        $saved = $this->saveFormat(['thousand_sep' => ' ', 'decimal_sep' => ',']);

        $this->assertSame(' ', $saved['thousand_sep'], 'the space was trimmed away');
        $this->assertSame(',', $saved['decimal_sep']);
    }

    public function test_a_non_breaking_space_survives_too(): void
    {
        // What a locale-aware paste actually contains.
        $nbsp  = "\u{00A0}";
        $saved = $this->saveFormat(['thousand_sep' => $nbsp]);

        $this->assertSame($nbsp, $saved['thousand_sep']);
    }

    public function test_an_ordinary_separator_is_unaffected(): void
    {
        $saved = $this->saveFormat(['thousand_sep' => '.', 'decimal_sep' => ',']);

        $this->assertSame('.', $saved['thousand_sep']);
        $this->assertSame(',', $saved['decimal_sep']);
    }

    public function test_an_apostrophe_survives_for_swiss_francs(): void
    {
        $saved = $this->saveFormat(['thousand_sep' => "'"]);

        $this->assertSame("'", $saved['thousand_sep']);
    }

    public function test_markup_is_still_stripped(): void
    {
        $saved = $this->saveFormat(['thousand_sep' => '<script>x</script>']);

        $this->assertStringNotContainsString('<', $saved['thousand_sep']);
        $this->assertStringNotContainsString('script', $saved['thousand_sep']);
    }

    public function test_control_characters_are_still_stripped(): void
    {
        $saved = $this->saveFormat(['thousand_sep' => "\x07"]);

        $this->assertSame('', $saved['thousand_sep']);
    }

    /**
     * Only the separators are exempt. A name padded with spaces should still
     * come back trimmed, or the exemption has leaked across the whole payload.
     */
    public function test_other_settings_are_still_trimmed(): void
    {
        $request = new \WP_REST_Request('POST', '/gratora/v1/admin/settings/org-profile');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['name' => '  Wildwater Trust  ']));

        rest_do_request($request);

        $saved = (new SettingsService())->get('org-profile');

        $this->assertSame('Wildwater Trust', $saved['name'] ?? null);
    }
}
