<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * A refusal and an unreachable host arrive at the same catch, and they mean
 * opposite things. Telling an org its keys were rejected when the site could not
 * resolve the API sends it to rotate credentials that were never wrong, while
 * the actual fault, this server's own networking, goes unmentioned.
 */
final class GatewayUnreachableTest extends IntegrationTestCase
{
    private bool $unreachable = false;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        Plugin::instance()->container->get(PayPalAccount::class)->forget();
        Plugin::instance()->container->get(StripeAccount::class)->forget();

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url)) return $pre;
            $isGateway = str_contains($url, 'paypal.com') || str_contains($url, 'stripe.com');
            if (! $isGateway) return $pre;

            if ($this->unreachable) {
                return new \WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
            }

            if (str_contains($url, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            return $this->reply(['id' => 'acct_test', 'charges_enabled' => true]);
        }, 10, 3);
    }

    /** @param array<string,mixed> $body */
    private function reply(array $body, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'],
            'cookies'  => [], 'filename' => null,
        ];
    }

    public function test_an_unreachable_stripe_is_not_reported_as_a_rejected_key(): void
    {
        $this->unreachable = true;

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/stripe/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode'            => 'test',
            'secret_key'      => 'sk_test_' . str_repeat('a', 24),
            'publishable_key' => 'pk_test_' . str_repeat('b', 24),
        ]));
        $res = rest_do_request($req);

        // 503, not 400: nothing was wrong with what the admin typed.
        $this->assertSame(503, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('dono_stripe_unreachable', $res->as_error()->get_error_code());
        $this->assertStringNotContainsStringIgnoringCase(
            'rejected',
            (string) $res->as_error()->get_error_message()
        );
    }

    public function test_an_unreachable_paypal_is_not_reported_as_rejected_credentials(): void
    {
        $this->unreachable = true;

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode'          => 'test',
            'client_id'     => 'client-unreachable',
            'client_secret' => 'secret-unreachable',
        ]));
        $res = rest_do_request($req);

        $this->assertSame(503, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('dono_paypal_unreachable', $res->as_error()->get_error_code());
        $this->assertStringNotContainsStringIgnoringCase(
            'rejected',
            (string) $res->as_error()->get_error_message()
        );
    }

    public function test_a_reachable_gateway_still_saves_normally(): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode'          => 'test',
            'client_id'     => 'client-fine',
            'client_secret' => 'secret-fine',
        ]));
        $res = rest_do_request($req);

        $this->assertLessThan(300, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertTrue(Plugin::instance()->container->get(PayPalAccount::class)->hasKeysFor(true));
    }
}
