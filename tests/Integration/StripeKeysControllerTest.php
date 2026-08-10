<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * Saving Stripe keys tests them against Stripe, so a typo is caught at save
 * time instead of at the first donation. A key Stripe refuses is not left
 * behind, a refused rotation keeps the pair that was working, and a stored
 * secret key is never readable back over REST.
 */
final class StripeKeysControllerTest extends IntegrationTestCase
{
    /** @var array<int,array{url:string,auth:string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->account()->forget();
    }

    /** Intercept the /v1/account retrieve; $ok=false makes Stripe reject the key. */
    private function mockStripe(bool $ok = true): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($ok) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) return $pre;

            $this->calls[] = [
                'url'  => $url,
                'auth' => (string) ($args['headers']['Authorization'] ?? ''),
            ];

            if (! $ok) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode(['error' => ['message' => 'Invalid API Key provided']]),
                    'response' => ['code' => 401, 'message' => 'Unauthorized'],
                    'cookies'  => [], 'filename' => null,
                ];
            }
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode([
                    'id'                => 'acct_live_org',
                    'charges_enabled'   => true,
                    'payouts_enabled'   => true,
                    'details_submitted' => true,
                    'email'             => 'org@example.test',
                    'country'           => 'US',
                    'business_profile'  => ['name' => 'Wildwater Trust'],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function save(string $mode, string $secret, string $publishable): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/stripe/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode' => $mode, 'secret_key' => $secret, 'publishable_key' => $publishable,
        ]));
        return rest_do_request($req);
    }

    /**
     * Resolve through the container, not `new StripeAccount(new Crypto())`: the
     * encryption key is DB-backed and generated on first use, so a fresh Crypto
     * can end up holding a different key than the booted singleton the
     * controller encrypted with.
     */
    private function account(): StripeAccount
    {
        return Plugin::instance()->container->get(StripeAccount::class);
    }

    public function test_valid_keys_are_verified_and_stored(): void
    {
        $this->mockStripe();
        $res = $this->save('test', 'sk_test_abcd1234', 'pk_test_abcd');

        $this->assertSame(200, $res->get_status());

        // The verification call authenticated with the key just submitted.
        $this->assertNotEmpty($this->calls, 'Stripe was called to verify the key');
        $this->assertSame('Bearer sk_test_abcd1234', $this->calls[0]['auth']);

        $acct = $this->account();
        $this->assertTrue($acct->hasKeysFor(true), 'test keys stored');
        $this->assertFalse($acct->hasKeysFor(false), 'live keys untouched');
        $this->assertSame('sk_test_abcd1234', $acct->secretKeyFor(true), 'secret round-trips through encryption');
        $this->assertSame('acct_live_org', $acct->accountId(), 'account id learned from the retrieve');
        $this->assertTrue($acct->canCharge());
    }

    public function test_secret_key_is_never_returned_over_rest(): void
    {
        $this->mockStripe();
        $body = $this->save('test', 'sk_test_supersecret', 'pk_test_abcd')->get_data();

        $encoded = (string) wp_json_encode($body);
        $this->assertStringNotContainsString('sk_test_supersecret', $encoded, 'the secret must never leave the server');
        $this->assertSame('cret', $body['account']['secret_hint_test'] ?? '', 'only a last-4 hint is exposed');
        $this->assertSame('pk_test_abcd', $body['account']['publishable_test'] ?? '');
    }

    public function test_keys_rejected_by_stripe_are_not_persisted(): void
    {
        $this->mockStripe(false);
        $res = $this->save('test', 'sk_test_wrong', 'pk_test_wrong');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_stripe_key_rejected', $res->get_data()['code'] ?? null);
        $this->assertFalse(
            $this->account()->hasKeysFor(true),
            'a key Stripe rejected must not be left behind'
        );
    }

    /**
     * The API client reads its key from the account, so a pair has to be
     * written before it can be tested and a failure has to put the old one
     * back. Blanking the mode instead leaves an org that was rotating its live
     * secret with no live secret at all, and donations stop until someone
     * retypes it.
     */
    public function test_a_rejected_rotation_leaves_the_working_key_alone(): void
    {
        $this->mockStripe();
        $this->save('live', 'sk_live_working', 'pk_live_working');
        $this->assertTrue($this->account()->hasKeysFor(false), 'precondition: live keys work');

        remove_all_filters('pre_http_request');
        $this->mockStripe(false);
        $res = $this->save('live', 'sk_live_typo', 'pk_live_typo');

        $this->assertSame(400, $res->get_status(), 'the rotation is refused');
        $this->assertSame(
            'sk_live_working',
            $this->account()->secretKeyFor(false),
            'and the key that was working is still the stored one'
        );
    }

    /**
     * The catch is reached by a network failure as well as by a rejection:
     * $this->api->get() throws for both. A Stripe that is briefly unreachable
     * must not cost an org its credentials.
     */
    public function test_an_unreachable_stripe_does_not_cost_the_stored_key(): void
    {
        $this->mockStripe();
        $this->save('live', 'sk_live_working', 'pk_live_working');

        remove_all_filters('pre_http_request');
        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (is_string($url) && str_starts_with($url, 'https://api.stripe.com/')) {
                return new \WP_Error('http_request_failed', 'cURL error 28: timed out');
            }
            return $pre;
        }, 5, 3);

        $res = $this->save('live', 'sk_live_rotated', 'pk_live_rotated');

        $this->assertGreaterThanOrEqual(400, $res->get_status(), 'the save is refused');
        $this->assertSame(
            'sk_live_working',
            $this->account()->secretKeyFor(false),
            'a timeout is not a reason to forget a working key'
        );
    }

    public function test_publishable_key_pasted_into_the_secret_field_is_caught(): void
    {
        $this->mockStripe();
        $res = $this->save('test', 'pk_test_abcd', 'pk_test_abcd');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_stripe_bad_key', $res->get_data()['code'] ?? null);
        $this->assertEmpty($this->calls, 'shape is checked before spending a Stripe call');
    }

    public function test_live_keys_saved_under_test_mode_are_caught(): void
    {
        $this->mockStripe();
        $res = $this->save('test', 'sk_live_abcd', 'pk_live_abcd');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_stripe_bad_key', $res->get_data()['code'] ?? null);
        $this->assertFalse($this->account()->hasKeysFor(true));
    }

    public function test_mismatched_pair_is_caught(): void
    {
        $this->mockStripe();
        $res = $this->save('test', 'sk_test_abcd', 'pk_live_abcd');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_stripe_bad_key', $res->get_data()['code'] ?? null);
    }

    public function test_removing_one_mode_leaves_the_other(): void
    {
        $this->mockStripe();
        $this->save('test', 'sk_test_abcd', 'pk_test_abcd');
        $this->save('live', 'sk_live_abcd', 'pk_live_abcd');
        $this->assertTrue($this->account()->hasKeysFor(true));
        $this->assertTrue($this->account()->hasKeysFor(false));

        $req = new WP_REST_Request('DELETE', '/dono/v1/gateways/stripe/keys');
        $req->set_param('mode', 'live');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($this->account()->hasKeysFor(true), 'test keys survive removing live');
        $this->assertFalse($this->account()->hasKeysFor(false));
    }

    public function test_saving_keys_requires_the_settings_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->mockStripe();

        $res = $this->save('test', 'sk_test_abcd', 'pk_test_abcd');

        $this->assertSame(403, $res->get_status());
        $this->assertFalse($this->account()->hasKeysFor(true));
    }
}
