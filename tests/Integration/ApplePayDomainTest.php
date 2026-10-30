<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Config\SystemSetting;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\Stripe\ApplePayDomain;
use Gratora\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * Apple Pay only renders once the domain is verified with Stripe, and that fails
 * silently in production: the button simply never appears.
 */
final class ApplePayDomainTest extends IntegrationTestCase
{
    private const FILE_KEY = 'apple_pay_domain_file';

    /** @var array<int,array{url:string,body:array<string,mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        SystemSetting::forget(self::FILE_KEY);
        SystemSetting::forget('apple_pay_domain_status');

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

    private function enable(string $mode = 'live'): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/gateways/stripe/apple-pay');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['mode' => $mode]));
        return rest_do_request($req);
    }

    public function test_enabling_registers_the_domain_with_stripe(): void
    {
        $this->mockStripe('active');

        $res = $this->enable();

        $this->assertSame(200, $res->get_status(), wp_json_encode($res->get_data()));
        $this->assertSame('active', $res->get_data()['apple_pay']['status'] ?? null);

        $register = $this->calls[0] ?? null;
        $this->assertNotNull($register);
        $this->assertStringContainsString('/payment_method_domains', $register['url']);
        $this->assertSame($this->applePay()->domain(), $register['body']['domain_name'] ?? null);
    }

    /** Stripe's own reason for an inactive domain must reach the admin. */
    public function test_stripe_verification_failure_is_surfaced(): void
    {
        $this->mockStripe('inactive', 'The file was not found at the expected URL.');

        $data = $this->enable()->get_data();

        $this->assertSame('inactive', $data['apple_pay']['status'] ?? null);
        $this->assertStringContainsString('not found', $data['apple_pay']['message'] ?? '');
    }

    public function test_status_is_reported_per_mode_on_the_stripe_status_route(): void
    {
        $this->mockStripe('active');
        $this->enable('live');

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/gateways/stripe/status'));
        $apple = $res->get_data()['apple_pay'] ?? [];

        $this->assertSame('active', $apple['live']['status'] ?? null);
        // Live and test are registered separately with Stripe.
        $this->assertSame('unknown', $apple['test']['status'] ?? null);
    }

    /**
     * A site that saved a value keeps serving it. bodyForRequest is the whole routing decision; maybeServeAssociationFile
     * only adds headers and exit around it, which no test can run.
     */
    public function test_the_well_known_path_serves_the_stored_file_verbatim(): void
    {
        $contents = '7B227073704964223A2241424331323334353637383930227D';
        SystemSetting::write(self::FILE_KEY, $contents);

        $body = $this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH);

        $this->assertSame($contents, $body, 'Apple compares the body byte for byte');
    }

    /** Query strings and a trailing slash must not defeat the match. */
    public function test_the_path_match_tolerates_slash_and_query(): void
    {
        SystemSetting::write(self::FILE_KEY, 'abc');

        $this->assertSame('abc', $this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH . '/'));
        $this->assertSame('abc', $this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH . '?v=1'));
    }

    /** Any other URL must fall through untouched. */
    public function test_other_paths_are_left_alone(): void
    {
        SystemSetting::write(self::FILE_KEY, 'somefile');

        $this->assertNull($this->applePay()->bodyForRequest('/donate/'));
        $this->assertNull($this->applePay()->bodyForRequest('/.well-known/other'));
    }

    public function test_nothing_is_served_when_no_file_is_stored(): void
    {
        $this->assertNull($this->applePay()->bodyForRequest(ApplePayDomain::WELL_KNOWN_PATH));
    }

    public function test_enabling_requires_the_settings_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->mockStripe();

        $res = $this->enable();

        $this->assertSame(403, $res->get_status());
        $this->assertEmpty($this->calls);
    }
}
