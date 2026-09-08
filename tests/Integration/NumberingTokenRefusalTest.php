<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Foundation\References\InvalidReferenceToken;
use FundKit\Foundation\References\ReferenceGenerator;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * The generator strips anything outside [A-Za-z0-9_-] before it mints, so a
 * separator of '.' or a prefix of 'AC/DC' produced references that never
 * matched the scheme the Numbering screen previewed and saved. Refuse the
 * value instead: the operator hands an accountant a numbering scheme.
 */
final class NumberingTokenRefusalTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function put(array $body): \WP_REST_Response|\WP_Error
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('PUT', '/fundkit/v1/admin/settings/numbering');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req);
    }

    public function test_a_separator_the_generator_would_strip_is_refused(): void
    {
        $res = $this->put(['separator' => '.']);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('fundkit_invalid_reference_token', $res->get_data()['code']);
        $this->assertStringContainsString('Separator', (string) $res->get_data()['message']);
        $this->assertSame('-', $this->settings()->get('numbering')['separator'], 'nothing was stored');
    }

    public function test_a_prefix_the_generator_would_strip_is_refused(): void
    {
        $res = $this->put(['prefixes' => ['donation' => 'AC/DC']]);

        $this->assertSame(400, $res->get_status());
        $this->assertStringContainsString('Donation prefix', (string) $res->get_data()['message']);
        $this->assertSame('DON', $this->settings()->get('numbering')['prefixes']['donation']);
    }

    public function test_an_empty_prefix_is_refused_rather_than_silently_replaced(): void
    {
        $res = $this->put(['prefixes' => ['receipt' => '']]);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('REC', $this->settings()->get('numbering')['prefixes']['receipt']);
    }

    public function test_a_scheme_the_generator_accepts_saves(): void
    {
        $res = $this->put(['separator' => '_', 'prefixes' => ['donation' => 'APPEAL_2026']]);

        $this->assertSame(200, $res->get_status());

        $stored = $this->settings()->get('numbering');
        $this->assertSame('_', $stored['separator']);
        $this->assertSame('APPEAL_2026', $stored['prefixes']['donation']);

        $minted = Plugin::instance()->container->get(ReferenceGenerator::class)->format('donation', 2026, 1);
        $this->assertSame('APPEAL_2026_2026_00001', $minted, 'what was saved is what is minted');
    }

    public function test_the_service_itself_refuses(): void
    {
        $this->expectException(InvalidReferenceToken::class);

        $this->settings()->update('numbering', ['separator' => '/']);
    }
}
