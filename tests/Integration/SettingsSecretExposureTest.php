<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * The Stripe webhook signing secret is the ONLY authentication on
 * /dono/v1/webhooks/stripe. Handing it out over the settings read let a holder
 * of the delegatable dono_manage_settings cap forge a paid donation without any
 * donations capability at all.
 */
final class SettingsSecretExposureTest extends IntegrationTestCase
{
    private const SECRET = 'whsec_real_signing_secret_value';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option('dono_gateway_config', [
            'test_mode' => true,
            'stripe'    => [
                'webhook_secret_test' => self::SECRET,
                'webhook_secret_live' => '',
                'enabled'             => true,
            ],
            'offline'   => ['enabled' => true, 'instructions' => 'Bank transfer please'],
        ]);
    }

    private function read(string $group = 'gateways'): array
    {
        return (array) rest_do_request(
            new WP_REST_Request('GET', "/dono/v1/admin/settings/{$group}")
        )->get_data();
    }

    private function write(array $body, string $group = 'gateways'): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', "/dono/v1/admin/settings/{$group}");
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body));
        return rest_do_request($req);
    }

    private function storedSecret(): string
    {
        $opt = get_option('dono_gateway_config', []);
        return (string) ($opt['stripe']['webhook_secret_test'] ?? '');
    }

    public function test_the_signing_secret_is_never_returned(): void
    {
        $data = $this->read();

        $this->assertStringNotContainsString(
            self::SECRET,
            (string) wp_json_encode($data),
            'the raw signing secret must not appear anywhere in the response'
        );
        $this->assertSame('***', $data['stripe']['webhook_secret_test'] ?? null);
    }

    /** The admin still needs to see whether one is configured. */
    public function test_an_unset_secret_stays_distinguishable_from_a_hidden_one(): void
    {
        $data = $this->read();

        $this->assertSame('', $data['stripe']['webhook_secret_live'] ?? null, 'not configured reads as empty');
        $this->assertSame('***', $data['stripe']['webhook_secret_test'] ?? null, 'configured reads as masked');
    }

    /** Non-secret settings must still round-trip normally. */
    public function test_non_secret_values_are_untouched(): void
    {
        $data = $this->read();

        $this->assertTrue($data['test_mode'] ?? false);
        $this->assertSame('Bank transfer please', $data['offline']['instructions'] ?? null);
    }

    /**
     * The settings panel reads the whole group and posts it back. Without the
     * restore step, saving any unrelated field would overwrite the real secret
     * with the mask and silently break every webhook.
     */
    public function test_saving_the_masked_value_back_does_not_destroy_the_secret(): void
    {
        $round = $this->read();
        $round['offline']['instructions'] = 'Updated instructions';

        $res = $this->write($round);

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertSame(self::SECRET, $this->storedSecret(), 'the stored secret survived the round trip');
        $this->assertSame('Updated instructions', get_option('dono_gateway_config')['offline']['instructions']);
    }

    /** A genuine replacement must still get through. */
    public function test_a_real_new_secret_still_saves(): void
    {
        $round = $this->read();
        $round['stripe']['webhook_secret_test'] = 'whsec_rotated';

        $this->write($round);

        $this->assertSame('whsec_rotated', $this->storedSecret());
    }

    /** And clearing one must still be possible. */
    public function test_a_secret_can_still_be_cleared(): void
    {
        $round = $this->read();
        $round['stripe']['webhook_secret_test'] = '';

        $this->write($round);

        $this->assertSame('', $this->storedSecret());
    }

    /** The write response must not leak it back either. */
    public function test_the_save_response_is_also_masked(): void
    {
        $round = $this->read();
        $data  = (array) $this->write($round)->get_data();

        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($data));
    }
}
