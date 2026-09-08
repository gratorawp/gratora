<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use FundKit\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * A signing secret belongs to the Stripe account that issued it. Keeping it
 * against a replaced account verifies nothing, while readiness ("Stripe
 * webhooks are signed"), the admin notice and the settings card all report
 * webhooks as configured. Every delivery is then refused, so a charged renewal
 * is never recorded and a refund never comes off the books.
 *
 * The provision that would replace it is allowed to fail: a restricted key
 * without webhook scope is accepted by the key check, and a Stripe this site
 * cannot reach fails the same way.
 */
final class StripeAccountSwapSecretTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option('fundkit_gateway_config', ['stripe' => [
            'webhook_secret_live'   => 'whsec_previous_org',
            'webhook_endpoint_live' => 'we_previous:bond',
        ]]);

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->saveKeys(false, 'sk_live_previous', 'pk_live_previous');
        $account->refresh(['id' => 'acct_previous_org', 'charges_enabled' => true]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    /** Stripe answers the key check for $accountId, and refuses everything else. */
    private function stripeAnswersAs(string $accountId): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($accountId) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            if (str_contains($url, '/v1/account')) {
                return $this->reply(['id' => $accountId, 'charges_enabled' => true]);
            }

            // The provisioning half fails, which is the case that matters: a
            // key the check accepts can still lack webhook scope.
            return $this->reply(['error' => ['message' => 'no scope']], 403);
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

    private function saveKeys(string $secret): int
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/gateways/stripe/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode'            => 'live',
            'secret_key'      => $secret,
            'publishable_key' => 'pk_live_new',
        ]));

        return rest_do_request($req)->get_status();
    }

    private function storedSecret(): string
    {
        $opt = get_option('fundkit_gateway_config', []);

        return (string) ($opt['stripe']['webhook_secret_live'] ?? '');
    }

    public function test_moving_to_another_stripe_account_drops_the_signing_secret(): void
    {
        $this->stripeAnswersAs('acct_a_different_org');

        $this->assertSame(200, $this->saveKeys('sk_live_different_org'));

        $this->assertSame(
            '',
            $this->storedSecret(),
            'a secret the new account never issued verifies nothing, and must not read as configured'
        );
    }

    public function test_rotating_a_key_on_the_same_account_keeps_its_secret(): void
    {
        $this->stripeAnswersAs('acct_previous_org');

        $this->assertSame(200, $this->saveKeys('sk_live_rotated'));

        $this->assertSame('whsec_previous_org', $this->storedSecret());
    }

    public function test_a_refused_key_leaves_the_secret_where_it_is(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            return is_string($url) && str_starts_with($url, 'https://api.stripe.com/')
                ? $this->reply(['error' => ['message' => 'Invalid API Key']], 401)
                : $pre;
        }, 10, 3);

        $this->assertSame(400, $this->saveKeys('sk_live_bogus'));
        $this->assertSame('whsec_previous_org', $this->storedSecret());
    }
}
