<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeWebhookProvisioner;
use RuntimeException;

/**
 * Provisioning the account's webhook endpoint must never leave the account
 * without one, and must never rotate a signing secret it did not have to.
 */
final class StripeWebhookProvisionOrderTest extends IntegrationTestCase
{
    /** @var array<int,array{method:string,url:string,body:string}> */
    private array $calls = [];

    private string $url = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        $this->url   = rest_url('dono/v1/webhooks/stripe');

        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => 'whsec_old'],
        ]);

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $account->refresh(['id' => 'acct_live_org', 'charges_enabled' => true]);
    }

    /**
     * @param array<int,array<string,mixed>> $endpoints What GET /webhook_endpoints answers.
     */
    private function mockStripe(array $endpoints, bool $createOk, bool $createReturnsSecret = true): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($endpoints, $createOk, $createReturnsSecret) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($args['body'] ?? '')];

            if ($method === 'GET') {
                return $this->response(['object' => 'list', 'data' => $endpoints]);
            }

            if ($method === 'DELETE') {
                return $this->response(['id' => 'we_old', 'deleted' => true]);
            }

            if (! $createOk) {
                return $this->response(['error' => ['message' => 'Something went wrong on our end.']], 500);
            }

            return $this->response($createReturnsSecret
                ? ['id' => 'we_new', 'secret' => 'whsec_new']
                : ['id' => 'we_new']);
        }, 10, 3);
    }

    /** @param array<string,mixed> $body */
    private function response(array $body, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function endpoint(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'we_old',
            'url'            => $this->url,
            'status'         => 'enabled',
            'api_version'    => StripeApi::API_VERSION,
            'enabled_events' => $this->subscribedEvents(),
        ], $overrides);
    }

    /** @return array<int,string> */
    private function subscribedEvents(): array
    {
        $ref  = new \ReflectionClass(StripeWebhookProvisioner::class);
        $prop = $ref->getConstant('EVENTS');

        return is_array($prop) ? $prop : [];
    }

    private function provision(): void
    {
        $c = Plugin::instance()->container;
        (new StripeWebhookProvisioner(
            $c->get(StripeApi::class),
            $c->get(StripeAccount::class),
        ))->provision(false);
    }

    private function storedSecret(): string
    {
        $opt = get_option('dono_gateway_config', []);

        return (string) ($opt['stripe']['webhook_secret_live'] ?? '');
    }

    /** @return array<int,string> */
    private function methods(): array
    {
        return array_map(static fn (array $c): string => $c['method'], $this->calls);
    }

    public function test_a_failed_create_leaves_the_existing_endpoint_and_secret_alone(): void
    {
        // Stale api_version, so the endpoint does need replacing.
        $this->mockStripe([$this->endpoint(['api_version' => '2020-08-27'])], false);

        try {
            $this->provision();
            $this->fail('a failed create must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Stripe API', $e->getMessage());
        }

        $this->assertNotContains(
            'DELETE',
            $this->methods(),
            'the endpoint that still works was deleted before its replacement existed'
        );
        $this->assertSame('whsec_old', $this->storedSecret(), 'and the secret that verifies it is still on file');
    }

    public function test_the_replacement_is_created_before_the_old_endpoint_is_dropped(): void
    {
        $this->mockStripe([$this->endpoint(['api_version' => '2020-08-27'])], true);

        $this->provision();

        $this->assertSame(['GET', 'POST', 'DELETE'], $this->methods(), 'create first, delete second');
        $this->assertSame('whsec_new', $this->storedSecret());
    }

    public function test_the_endpoint_subscribes_to_refunds_changing_state(): void
    {
        $this->mockStripe([], true);

        $this->provision();

        $created = array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === 'POST'
        ));
        $this->assertCount(1, $created);

        parse_str($created[0]['body'], $body);
        $this->assertContains(
            'charge.refund.updated',
            (array) ($body['enabled_events'] ?? []),
            'a submitted bank refund that later fails has to reach the site'
        );
    }

    public function test_an_endpoint_that_is_already_right_is_left_as_it_is(): void
    {
        $this->mockStripe([$this->endpoint()], true);

        $this->provision();

        $this->assertSame(['GET'], $this->methods(), 'nothing to change, so nothing is created or deleted');
        $this->assertSame(
            'whsec_old',
            $this->storedSecret(),
            'a re-save must not rotate the secret Stripe is still signing retries with'
        );
    }

    public function test_a_matching_endpoint_whose_secret_is_unknown_is_replaced(): void
    {
        update_option('dono_gateway_config', ['stripe' => []]);
        $this->mockStripe([$this->endpoint()], true);

        $this->provision();

        $this->assertSame(['GET', 'POST', 'DELETE'], $this->methods());
        $this->assertSame('whsec_new', $this->storedSecret(), 'an endpoint we cannot verify is worth nothing');
    }

    public function test_a_create_that_returns_no_secret_keeps_the_working_endpoint(): void
    {
        $this->mockStripe([$this->endpoint(['api_version' => '2020-08-27'])], true, false);

        try {
            $this->provision();
            $this->fail('an endpoint whose secret is unknown cannot be reported as provisioned');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('signing secret', $e->getMessage());
        }

        $this->assertSame('whsec_old', $this->storedSecret(), 'the secret still verifying deliveries stays');
        $deleted = array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === 'DELETE'
        ));
        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith('we_new', $deleted[0]['url'], 'the useless new endpoint goes, not the working one');
    }

    public function test_an_endpoint_missing_events_is_replaced(): void
    {
        $short = $this->subscribedEvents();
        array_pop($short);
        $this->mockStripe([$this->endpoint(['enabled_events' => $short])], true);

        $this->provision();

        $this->assertSame(['GET', 'POST', 'DELETE'], $this->methods());
    }

    public function test_a_disabled_endpoint_is_replaced(): void
    {
        $this->mockStripe([$this->endpoint(['status' => 'disabled'])], true);

        $this->provision();

        $this->assertSame(['GET', 'POST', 'DELETE'], $this->methods());
    }
}
