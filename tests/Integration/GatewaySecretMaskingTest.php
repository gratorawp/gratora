<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * The Stripe webhook signing secret is write-only over REST: GET returns a
 * mask (never the stored value), and a resubmitted mask preserves it, so a
 * routine settings save neither blanks the secret nor leaks it to a caller.
 */
final class GatewaySecretMaskingTest extends IntegrationTestCase
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

    public function test_webhook_secret_is_masked_on_read_and_preserved_on_write(): void
    {
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_realsecret']]);

        // Read returns a mask, not the real value, but stays truthy for status.
        $shown = $this->show('gateways');
        $masked = (string) ($shown['stripe']['webhook_secret_test'] ?? '');
        $this->assertNotSame('whsec_realsecret', $masked, 'the real secret is never returned');
        $this->assertNotEmpty($masked, 'a configured secret still reads as set');

        // Resubmitting the mask keeps the stored value.
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => $masked]]);
        $stored = (array) get_option('dono_gateway_config');
        $this->assertSame('whsec_realsecret', $stored['stripe']['webhook_secret_test'] ?? '');

        // A genuinely new value overwrites.
        $this->put('gateways', ['stripe' => ['webhook_secret_test' => 'whsec_rotated']]);
        $stored = (array) get_option('dono_gateway_config');
        $this->assertSame('whsec_rotated', $stored['stripe']['webhook_secret_test'] ?? '');
    }
}
