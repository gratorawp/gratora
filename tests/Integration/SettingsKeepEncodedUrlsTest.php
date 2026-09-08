<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * WordPress's text sanitizers delete every %XX run they find and then collapse
 * the whitespace that leaves, so a tracked link pasted into a template arrived
 * stored as a dead one. No error was raised; the panel simply reloaded showing
 * the mangled text, and every donor receiving it got the broken link.
 */
final class SettingsKeepEncodedUrlsTest extends IntegrationTestCase
{
    private function save(string $group, array $body): void
    {
        $request = new WP_REST_Request('POST', '/fundkit/v1/admin/settings/' . $group);
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        rest_do_request($request);
    }

    private function get(string $group): array
    {
        return (new SettingsService())->get($group);
    }

    public function test_a_percent_encoded_link_survives_a_save(): void
    {
        $url = 'https://example.org/report?utm_campaign=spring%20appeal';

        $this->save('org-profile', ['name' => $url]);

        $this->assertSame($url, $this->get('org-profile')['name'] ?? null);
    }

    /**
     * strip_tags reads "<3" as an unclosed tag and takes the rest of the body
     * with it, so escaping has to happen before stripping.
     */
    public function test_a_stray_angle_bracket_does_not_eat_the_rest(): void
    {
        $this->save('org-profile', ['name' => 'We <3 our donors, every one']);

        $this->assertStringContainsString('our donors, every one', (string) ($this->get('org-profile')['name'] ?? ''));
    }

    public function test_a_script_tag_is_still_removed(): void
    {
        $this->save('org-profile', ['name' => 'Trust<script>alert(1)</script>']);

        $name = (string) ($this->get('org-profile')['name'] ?? '');

        $this->assertStringNotContainsString('<script', $name);
        $this->assertStringNotContainsString('alert(1)', $name);
    }

    public function test_ordinary_settings_are_still_trimmed(): void
    {
        $this->save('org-profile', ['name' => '  Wildwater Trust  ']);

        $this->assertSame('Wildwater Trust', $this->get('org-profile')['name'] ?? null);
    }
}
