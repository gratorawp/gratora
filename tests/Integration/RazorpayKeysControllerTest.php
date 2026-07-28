<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Razorpay\RazorpayAccount;
use WP_REST_Request;

/**
 * Razorpay key management. Keys are verified with Razorpay before they are
 * stored, secrets are write-only over REST, and a live key saved under Test is
 * refused outright: that mistake charges real cards on a form the admin
 * believes is safe.
 *
 * The account is always resolved through the container. A freshly constructed
 * RazorpayAccount would hold a different Crypto key than the booted singleton
 * and could not decrypt what the controller stored.
 */
final class RazorpayKeysControllerTest extends IntegrationTestCase
{
    private bool $razorpayAccepts = true;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->account()->forget();

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'api.razorpay.com')) return $pre;

            if (! $this->razorpayAccepts) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode([
                        'error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed'],
                    ]),
                    'response' => ['code' => 401, 'message' => 'Unauthorized'],
                    'cookies'  => [], 'filename' => null,
                ];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['entity' => 'collection', 'count' => 0, 'items' => []]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function account(): RazorpayAccount
    {
        return Plugin::instance()->container->get(RazorpayAccount::class);
    }

    private function save(string $mode, string $keyId, string $secret, string $webhook = ''): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/razorpay/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode'           => $mode,
            'key_id'         => $keyId,
            'key_secret'     => $secret,
            'webhook_secret' => $webhook,
        ]));
        return rest_do_request($req);
    }

    public function test_valid_test_keys_are_verified_and_stored(): void
    {
        $res = $this->save('test', 'rzp_test_abc123', 'secret_value_1234', 'whsec_1');

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertTrue($res->get_data()['connected'] ?? false);
        $this->assertTrue($this->account()->hasKeysFor(true));
        $this->assertSame('secret_value_1234', $this->account()->keySecretFor(true));
        $this->assertSame('whsec_1', $this->account()->webhookSecret(true));
    }

    /** The secret is write-only: only a last-4 hint ever comes back. */
    public function test_the_secret_is_never_returned(): void
    {
        $data = $this->save('test', 'rzp_test_abc123', 'secret_value_1234')->get_data();

        $this->assertStringNotContainsString('secret_value_1234', (string) wp_json_encode($data));
        $this->assertSame('1234', $data['account']['secret_hint_test'] ?? null);
    }

    /** The one mistake that charges real cards on a form believed to be safe. */
    public function test_a_live_key_saved_under_test_is_refused(): void
    {
        $res = $this->save('test', 'rzp_live_abc123', 'secret_value_1234');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_razorpay_bad_key', $res->get_data()['code'] ?? null);
        $this->assertFalse($this->account()->hasKeysFor(true));
    }

    public function test_a_test_key_saved_under_live_is_refused(): void
    {
        $res = $this->save('live', 'rzp_test_abc123', 'secret_value_1234');

        $this->assertSame(400, $res->get_status());
        $this->assertFalse($this->account()->hasKeysFor(false));
    }

    public function test_the_key_id_pasted_into_the_secret_field_is_caught(): void
    {
        $res = $this->save('test', 'rzp_test_abc123', 'rzp_test_abc123');

        $this->assertSame(400, $res->get_status());
        $this->assertStringContainsString('key id again', (string) ($res->get_data()['message'] ?? ''));
    }

    public function test_something_that_is_not_a_razorpay_key_is_refused(): void
    {
        $res = $this->save('test', 'sk_test_stripe_key', 'secret_value_1234');

        $this->assertSame(400, $res->get_status());
        $this->assertFalse($this->account()->hasKeysFor(true));
    }

    /** Keys Razorpay rejects must not be left behind half-stored. */
    public function test_keys_razorpay_rejects_are_not_kept(): void
    {
        $this->razorpayAccepts = false;

        $res = $this->save('test', 'rzp_test_abc123', 'wrong_secret_9999');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_razorpay_key_rejected', $res->get_data()['code'] ?? null);
        $this->assertFalse($this->account()->hasKeysFor(true));
        $this->assertFalse($this->account()->isConnected());
    }

    public function test_removing_one_mode_leaves_the_other(): void
    {
        $this->save('test', 'rzp_test_abc123', 'secret_value_1234');
        $this->save('live', 'rzp_live_xyz789', 'secret_value_5678');

        $req = new WP_REST_Request('DELETE', '/dono/v1/gateways/razorpay/keys');
        $req->set_param('mode', 'test');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $this->assertFalse($this->account()->hasKeysFor(true));
        $this->assertTrue($this->account()->hasKeysFor(false));
    }

    /**
     * canCharge answers "may the form offer Razorpay", which is asked before any
     * donation has fixed a mode. Keying it off the per-operation mode override
     * on a shared instance would let a live call earlier in a request hide
     * Razorpay from a test-mode form.
     */
    public function test_can_charge_does_not_depend_on_the_current_mode(): void
    {
        $this->save('test', 'rzp_test_abc123', 'secret_value_1234');

        $account = $this->account();
        $account->useTestMode(false);

        $this->assertTrue($account->canCharge(), 'still chargeable with only test keys');
    }

    public function test_saving_keys_requires_the_settings_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $res = $this->save('test', 'rzp_test_abc123', 'secret_value_1234');

        $this->assertSame(403, $res->get_status());
        $this->assertFalse($this->account()->hasKeysFor(true));
    }
}
