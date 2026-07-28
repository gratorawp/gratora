<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\ApplePayDomain;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * Apple Pay only renders when the domain association file is served AND the
 * domain is verified with Stripe. Both halves fail silently in production (the
 * button simply never appears), so both are asserted here.
 */
final class ApplePayDomainTest extends IntegrationTestCase
{
    /** @var array<int,array{url:string,body:array<string,mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->applePay()->forgetAssociationFile();

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->forget();
        $account->saveKeys(false, 'sk_live_abcd', 'pk_live_abcd');
    }

    private function applePay(): ApplePayDomain
    {
        return Plugin::instance()->container->get(ApplePayDomain::class);
    }

    /** @param string $status what Stripe reports for apple_pay. */
    private function mockStripe(string $status = 'active', string $error = ''): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($status, $error) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) return $pre;

            $body = [];
            if (! empty($args['body']) && is_string($args['body'])) {
                parse_str($args['body'], $body);
            }
            $this->calls[] = ['url' => $url, 'body' => $body];

            $apple = ['status' => $status];
            if ($error !== '') {
                $apple['status_details'] = ['error_message' => $error];
            }

            $obj = ['id' => 'pmd_test_1', 'domain_name' => 'example.org', 'apple_pay' => $apple];

            // The list endpoint wraps the object.
            $payload = str_contains($url, 'payment_method_domains?') ? ['data' => [$obj]] : $obj;

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($payload),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function enable(string $file = '', string $mode = 'live'): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/stripe/apple-pay');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['mode' => $mode, 'association_file' => $file]));
        return rest_do_request($req);
    }

    public function test_enabling_stores_the_file_and_registers_the_domain(): void
    {
        $this->mockStripe('active');

        $res = $this->enable('7B227073704964223A224142433132333435');

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertSame('active', $res->get_data()['apple_pay']['status'] ?? null);

        $this->assertTrue($this->applePay()->isFileReady(), 'the association file is stored');

        $register = $this->calls[0] ?? null;
        $this->assertNotNull($register);
        $this->assertStringContainsString('/payment_method_domains', $register['url']);
        $this->assertSame($this->applePay()->domain(), $register['body']['domain_name'] ?? null);
    }

    /**
     * Without the file Stripe's verification cannot pass, so refuse early with
     * an actionable message rather than registering a domain that will fail.
     */
    public function test_enabling_without_the_file_is_refused_before_calling_stripe(): void
    {
        $this->mockStripe();

        $res = $this->enable('');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_apple_pay_no_file', $res->get_data()['code'] ?? null);
        $this->assertEmpty($this->calls, 'no Stripe call is spent when the precondition fails');
    }

    /** Stripe's own reason for an inactive domain must reach the admin. */
    public function test_stripe_verification_failure_is_surfaced(): void
    {
        $this->mockStripe('inactive', 'The file was not found at the expected URL.');

        $data = $this->enable('7B227073704964223A224142431')->get_data();

        $this->assertSame('inactive', $data['apple_pay']['status'] ?? null);
        $this->assertStringContainsString('not found', $data['apple_pay']['message'] ?? '');
    }

    public function test_status_is_reported_per_mode_on_the_stripe_status_route(): void
    {
        $this->mockStripe('active');
        $this->enable('7B227073704964223A2241424331', 'live');

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/gateways/stripe/status'));
        $apple = $res->get_data()['apple_pay'] ?? [];

        $this->assertTrue($apple['has_file'] ?? false);
        $this->assertSame('active', $apple['live']['status'] ?? null);
        // Live and test are registered separately with Stripe.
        $this->assertSame('unknown', $apple['test']['status'] ?? null);
    }

    /**
     * bodyForRequest is the whole routing decision; maybeServeAssociationFile
     * only adds headers and exit around it, which no test can run.
     */
    public function test_the_well_known_path_serves_the_stored_file_verbatim(): void
    {
        $contents = '7B227073704964223A2241424331323334353637383930227D';
        $this->applePay()->storeAssociationFile($contents);

        $body = $this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH);

        $this->assertSame($contents, $body, 'Apple compares the body byte for byte');
    }

    /** Query strings and a trailing slash must not defeat the match. */
    public function test_the_path_match_tolerates_slash_and_query(): void
    {
        $this->applePay()->storeAssociationFile('abc');

        $this->assertSame('abc', $this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH . '/'));
        $this->assertSame('abc', $this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH . '?v=1'));
    }

    /** Any other URL must fall through untouched. */
    public function test_other_paths_are_left_alone(): void
    {
        $this->applePay()->storeAssociationFile('somefile');

        $this->assertNull($this->applePay()->bodyForRequest('/donate/'));
        $this->assertNull($this->applePay()->bodyForRequest('/.well-known/other'));
    }

    /** With nothing stored, stay out of the way of a file placed on disk. */
    public function test_nothing_is_served_when_no_file_is_stored(): void
    {
        $this->assertNull($this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH));
    }

    public function test_enabling_requires_the_settings_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->mockStripe();

        $res = $this->enable('7B227073704964223A2241');

        $this->assertSame(403, $res->get_status());
        $this->assertFalse($this->applePay()->isFileReady());
    }
}
