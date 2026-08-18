<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\Stripe\StripeWebhookProvisioner;
use ReflectionClass;

/**
 * Two webhook endpoints can sit at the same URL on a Stripe account. Keeping a
 * usable one has to clear the others out: an endpoint pinned to an older
 * api_version keeps delivering events shaped the way the handlers do not read,
 * signed with a secret the site does not hold, and every one of those
 * deliveries counts toward Stripe disabling the endpoint.
 */
final class StripeWebhookDuplicateEndpointTest extends IntegrationTestCase
{
    /** @var array<int,array{method:string,url:string}> */
    private array $calls = [];

    private string $url = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        $this->url   = rest_url('dono/v1/webhooks/stripe');

        update_option('dono_gateway_config', ['stripe' => []]);

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $account->refresh(['id' => 'acct_live_org', 'charges_enabled' => true]);

        $this->seedProvisioned('we_good', 'whsec_old');
    }

    /**
     * The state a successful provision leaves behind: the endpoint on the
     * account, its secret on file, and whatever the provisioner records to
     * recognize the pair later. Driven through provision() rather than written
     * by hand so the tests below never assert against their own idea of it.
     */
    private function seedProvisioned(string $id, string $secret): void
    {
        $mock = function ($pre, $args, $url) use ($id, $secret) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            $body = strtoupper((string) ($args['method'] ?? 'GET')) === 'GET'
                ? ['object' => 'list', 'data' => []]
                : ['id' => $id, 'secret' => $secret];

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };

        add_filter('pre_http_request', $mock, 10, 3);
        $this->provision();
        remove_filter('pre_http_request', $mock, 10);
    }

    /** @param array<int,array<string,mixed>> $endpoints */
    private function mockStripe(array $endpoints): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($endpoints) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            $method        = strtoupper((string) ($args['method'] ?? 'GET'));
            $this->calls[] = ['method' => $method, 'url' => $url];

            if ($method === 'GET') {
                $body = ['object' => 'list', 'data' => $endpoints];
            } elseif ($method === 'DELETE') {
                $body = ['deleted' => true];
            } else {
                $body = ['id' => 'we_new', 'secret' => 'whsec_new'];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);
    }

    /** @param array<string,mixed> $overrides */
    private function endpoint(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'we_good',
            'url'            => $this->url,
            'status'         => 'enabled',
            'api_version'    => StripeApi::API_VERSION,
            'enabled_events' => $this->subscribedEvents(),
        ], $overrides);
    }

    /** @return array<int,string> */
    private function subscribedEvents(): array
    {
        $events = (new ReflectionClass(StripeWebhookProvisioner::class))->getConstant('EVENTS');

        return is_array($events) ? $events : [];
    }

    private function provision(): void
    {
        $c = Plugin::instance()->container;
        (new StripeWebhookProvisioner(
            $c->get(StripeApi::class),
            $c->get(StripeAccount::class),
        ))->provision(false);
    }

    /** @return array<int,string> */
    private function methods(): array
    {
        return array_map(static fn (array $c): string => $c['method'], $this->calls);
    }

    /** @return array<int,string> */
    private function deletedIds(): array
    {
        $ids = [];
        foreach ($this->calls as $call) {
            if ($call['method'] !== 'DELETE') {
                continue;
            }
            $ids[] = (string) substr($call['url'], (int) strrpos($call['url'], '/') + 1);
        }

        return $ids;
    }

    private function storedSecret(): string
    {
        $opt = get_option('dono_gateway_config', []);

        return (string) ($opt['stripe']['webhook_secret_live'] ?? '');
    }

    public function test_a_stale_duplicate_listed_first_is_dropped(): void
    {
        $this->mockStripe([
            $this->endpoint(['id' => 'we_stale', 'api_version' => '2020-08-27']),
            $this->endpoint(['id' => 'we_good']),
        ]);

        $this->provision();

        $this->assertSame(['we_stale'], $this->deletedIds(), 'the endpoint delivering the wrong shape stays');
        $this->assertNotContains('POST', $this->methods(), 'the usable endpoint did not need replacing');
        $this->assertSame('whsec_old', $this->storedSecret(), 'and its secret was not rotated');
    }

    public function test_a_stale_duplicate_listed_last_is_dropped(): void
    {
        $this->mockStripe([
            $this->endpoint(['id' => 'we_good']),
            $this->endpoint(['id' => 'we_stale', 'api_version' => '2020-08-27']),
        ]);

        $this->provision();

        $this->assertSame(['we_stale'], $this->deletedIds(), 'order of the list cannot decide what survives');
        $this->assertNotContains('POST', $this->methods());
        $this->assertSame('whsec_old', $this->storedSecret());
    }

    public function test_a_disabled_duplicate_beside_a_working_endpoint_is_dropped(): void
    {
        $this->mockStripe([
            $this->endpoint(['id' => 'we_good']),
            $this->endpoint(['id' => 'we_dead', 'status' => 'disabled']),
        ]);

        $this->provision();

        $this->assertSame(['we_dead'], $this->deletedIds());
        $this->assertSame('whsec_old', $this->storedSecret());
    }

    public function test_endpoints_at_other_urls_are_left_alone(): void
    {
        $this->mockStripe([
            $this->endpoint(['id' => 'we_theirs', 'url' => 'https://example.org/other', 'api_version' => '2020-08-27']),
            $this->endpoint(['id' => 'we_good']),
        ]);

        $this->provision();

        $this->assertSame([], $this->deletedIds(), 'another integration\'s endpoint is not ours to delete');
        $this->assertSame(['GET'], $this->methods());
    }

    public function test_two_usable_endpoints_are_both_replaced(): void
    {
        // Bonded to we_one, so the secret is no reason to replace it and the
        // count of usable endpoints is the only rule left that can. Seeded to
        // we_good instead, both would be replaced because neither is bonded,
        // and the rule under test would never be reached.
        $this->seedProvisioned('we_one', 'whsec_old');

        $this->mockStripe([
            $this->endpoint(['id' => 'we_one']),
            $this->endpoint(['id' => 'we_two']),
        ]);

        $this->provision();

        $this->assertSame(['GET', 'POST', 'DELETE', 'DELETE'], $this->methods(), 'created before either is dropped');
        $this->assertSame(['we_one', 'we_two'], $this->deletedIds());
        $this->assertSame('whsec_new', $this->storedSecret());
    }
}
