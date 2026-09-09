<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Foundation\References\ReferenceGenerator;
use Gratora\Foundation\Time\FrozenClock;
use Gratora\Settings\SettingsService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WP_REST_Request;

/**
 * Numbering the panel would accept and the column could not hold, and a
 * restore that ended as a fatal halfway through.
 */
final class ToolsAndNumberingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function generatorAt(string $utc): ReferenceGenerator
    {
        return new ReferenceGenerator(new FrozenClock(new DateTimeImmutable($utc, new DateTimeZone('UTC'))));
    }


    public function test_a_numbering_format_too_long_for_the_column_is_refused(): void
    {
        // Values the panel itself offers: a twelve-digit counter beside the
        // thirteen-character prefix test-mode donations mint under.
        $this->expectException(InvalidArgumentException::class);
        $this->settings()->update('numbering', ['padding' => 12, 'separator' => '---']);
    }

    public function test_an_over_long_prefix_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->settings()->update('numbering', ['prefixes' => ['donation' => str_repeat('A', 40)]]);
    }

    public function test_a_format_that_fits_is_accepted(): void
    {
        $saved = $this->settings()->update('numbering', ['padding' => 6, 'separator' => '-']);

        $this->assertSame(6, (int) $saved['padding']);
    }

    public function test_the_route_says_why_rather_than_erroring(): void
    {
        $req = new WP_REST_Request('PUT', '/gratora/v1/admin/settings/numbering');
        $req->set_param('group', 'numbering');
        $req->set_header('content-type', 'application/json');
        $req->set_body('{"padding":12,"separator":"---"}');

        $res = rest_do_request($req);

        $this->assertSame(422, $res->get_status());
        $this->assertSame('gratora_invalid_setting', (string) $res->get_data()['code']);
    }


    private function numbering(bool $resetYearly): void
    {
        update_option('gratora_reference_settings', [
            'include_year' => true,
            'reset_yearly' => $resetYearly,
            'padding'      => 5,
            'separator'    => '-',
        ]);
    }

    public function test_a_new_year_starts_at_one_after_a_year_of_continuous_numbering(): void
    {
        $this->numbering(false);
        $this->generatorAt('2026-06-01 10:00:00')->next('donation');
        $this->generatorAt('2026-06-01 10:00:00')->next('donation');

        $this->numbering(true);
        $first = $this->generatorAt('2027-01-02 10:00:00')->next('donation');

        $this->assertSame('DON-2027-00001', $first, 'the yearly reset never produced 00001 again');
    }

    /** Within the same year both counters print the same string, so one must clear the other. */
    public function test_turning_the_reset_on_mid_year_does_not_reissue(): void
    {
        $this->numbering(false);
        $this->generatorAt('2026-06-01 10:00:00')->next('donation');
        $this->generatorAt('2026-06-01 10:00:00')->next('donation');

        $this->numbering(true);
        $next = $this->generatorAt('2026-06-02 10:00:00')->next('donation');

        $this->assertSame('DON-2026-00003', $next);
    }


    private function import(array $settings): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/tools/import');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['settings' => $settings]));

        return rest_do_request($req);
    }

    public function test_a_restore_carrying_an_impossible_numbering_format_is_reported(): void
    {
        $res = $this->import([
            'gratora_reference_settings' => ['padding' => 12, 'separator' => '---'],
        ]);

        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $this->assertGreaterThanOrEqual(400, $res->get_status(), 'the restore has to say what it refused');
    }

    public function test_a_restore_does_not_carry_a_logo_id_across_sites(): void
    {
        update_option('gratora_receipt_settings', ['logo_attachment_id' => 0]);

        $this->import([
            'gratora_receipt_settings' => ['logo_attachment_id' => 4242, 'header_title' => 'Their receipt'],
        ]);

        $stored = (array) get_option('gratora_receipt_settings', []);

        $this->assertSame(0, (int) ($stored['logo_attachment_id'] ?? 0));
        $this->assertSame('Their receipt', (string) ($stored['header_title'] ?? ''), 'the rest of the group still lands');
    }

    /**
     * The counter refusal is the message a French site's admin reads when they
     * type today's last reference rather than the next one. It arrived in
     * English with its comparison operator escaped into an entity.
     */
    public function test_a_counter_refusal_reaches_the_admin_translated_and_renderable(): void
    {
        $set = static function (int $next): \WP_REST_Response {
            $req = new WP_REST_Request('POST', '/gratora/v1/admin/numbering/counter');
            $req->set_header('content-type', 'application/json');
            $req->set_body((string) wp_json_encode(['scope' => 'donation', 'next' => $next]));

            return rest_do_request($req);
        };

        // The probe for translation: it fires only if the string passes through
        // __(), and it needs no language pack.
        $filter = static function ($translated, $text, $domain) {
            return $domain === 'gratora' && str_contains($text, 'counter is already at')
                ? 'COUNTER REFUSED'
                : $translated;
        };
        add_filter('gettext', $filter, 10, 3);

        try {
            // The counter holds the last value used, so setting the next
            // number to 50 leaves it at 49: 49 is the number that repeats.
            $set(50);
            $res = $set(49);

            $this->assertSame(400, $res->get_status());

            $message = (string) $res->as_error()->get_error_message();
            $this->assertSame('COUNTER REFUSED', $message);
            $this->assertStringNotContainsString('&gt;', $message);
        } finally {
            remove_filter('gettext', $filter, 10);
        }
    }
}
