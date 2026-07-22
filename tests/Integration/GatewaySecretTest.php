<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * The Stripe webhook signing secret round-trips over REST rather than reading
 * back as a mask, so the settings field can reveal it. That puts the whole
 * burden on the capability gate, and makes "a partial save must not blank it"
 * the property worth pinning down.
 */
final class GatewaySecretTest extends IntegrationTestCase
{
    private function put(string $group, array $body): void
    {
        $req = new WP_REST_Request('PUT', "/dono/v1/admin/settings/{$group}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));
        rest_do_request($req);
    }

    private function show(string $group): array
    {
        return (array) rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/settings/{$group}"))->get_data();
    }

    private function storedSecret(): string
    {
        $stored = (array) get_option('dono_gateway_config');
        return (string) ($stored['stripe']['webhook_secret_test'] ?? '');
    }

    public function test_a_settings_manager_reads_back_the_real_secret(): void
    {
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_realsecret']]);

        $shown = $this->show('gateways');
        $this->assertSame(
            'whsec_realsecret',
            $shown['stripe']['webhook_secret_test'] ?? '',
            'the stored secret comes back so the field can reveal it'
        );
    }

    public function test_a_save_that_omits_the_secret_leaves_it_intact(): void
    {
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_realsecret']]);

        // Saving an unrelated gateway must not blank the secret out from under it.
        $this->put('gateways', ['offline' => ['enabled' => true]]);

        $this->assertSame('whsec_realsecret', $this->storedSecret());
    }

    public function test_a_new_value_rotates_the_secret(): void
    {
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_realsecret']]);
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_rotated']]);

        $this->assertSame('whsec_rotated', $this->storedSecret());
    }

    public function test_the_secret_is_unreachable_without_the_settings_cap(): void
    {
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_realsecret']]);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/settings/gateways'));

        $this->assertContains($res->get_status(), [401, 403], 'a subscriber cannot read gateway settings');
        $this->assertStringNotContainsString(
            'whsec_realsecret',
            (string) wp_json_encode($res->get_data()),
            'the secret never appears in a denied response'
        );
    }
}
