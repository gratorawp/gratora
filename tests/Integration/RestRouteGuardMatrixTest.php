<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

/**
 * Every route this plugin registers, checked against the live registry rather
 * than by reading the source.
 *
 * Two things a spot-check keeps missing. A route added later can be public
 * because someone copied a neighbouring registration, and nobody notices
 * because nothing enumerates them. And a route can name a permission callback
 * that never actually refuses anyone, which reads as protected and is not.
 *
 * So: the public routes are a list somebody had to write down, and everything
 * else has to turn an anonymous caller away when actually dispatched.
 */
final class RestRouteGuardMatrixTest extends IntegrationTestCase
{
    private const NAMESPACE_ROOT = '/fundkit/v1';

    /**
     * Deliberately reachable without authentication, and why. Adding to this
     * list is the review: if a new route needs to be here, that is the moment
     * to say who can reach it and what stops them abusing it.
     */
    private const PUBLIC_ROUTES = [
        '/fundkit/v1'                                              => 'the namespace index, registered by WordPress itself',
        '/fundkit/v1/donations'                                    => 'the donation form posts here; honeypot, form token and IP/email quotas gate it',
        '/fundkit/v1/donations/(?P<reference>[A-Za-z0-9_\-]+)'     => 'donation status; the per-donation status_token is the auth, and the body carries no PII',
        '/fundkit/v1/webhooks/(?P<gateway>[a-z0-9_-]+)'            => 'gateways cannot authenticate to WordPress; the signature is the auth layer',
        '/fundkit/v1/receipts/(?P<receipt_id>\d+)/download'        => 'a magic-link token scoped to purpose and receipt id, cross-checked against the donor',
        '/fundkit/v1/gateways/paypal/capture'                      => 'the browser finishes the payment here; the per-donation status_token is the auth',
        '/fundkit/v1/gateways/paypal/subscription'                 => 'as above, for the subscription the buyer just approved',
    ];

    /** @return array<string, array<int, array<string, mixed>>> */
    private function routes(): array
    {
        $server = rest_get_server();
        do_action('rest_api_init', $server);

        // The namespace exactly, not a prefix: another suite registers a
        // /fundkit-addon/v1 double to exercise the extension seam, and a
        // prefix match adopts it as one of ours.
        return array_filter(
            $server->get_routes(),
            static fn (string $route): bool => $route === self::NAMESPACE_ROOT
                || str_starts_with($route, self::NAMESPACE_ROOT . '/'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** @return list<string> routes reachable with no permission callback at all */
    private function openRoutes(): array
    {
        $open = [];
        foreach ($this->routes() as $route => $handlers) {
            foreach ($handlers as $handler) {
                $callback = $handler['permission_callback'] ?? null;
                if ($callback === null || $callback === '__return_true') {
                    $open[] = $route;
                }
            }
        }

        return array_values(array_unique($open));
    }

    public function test_the_plugin_registers_the_routes_this_asserts_about(): void
    {
        // A registry that came back empty would make every assertion below pass
        // while checking nothing.
        $this->assertGreaterThan(50, count($this->routes()));
    }

    public function test_no_route_is_public_without_being_written_down(): void
    {
        $undeclared = array_diff($this->openRoutes(), array_keys(self::PUBLIC_ROUTES));

        $this->assertSame(
            [],
            array_values($undeclared),
            "These routes are reachable by anyone and are not in PUBLIC_ROUTES.\n"
            . "If that is intended, add each one with the reason it is safe. If it is not,\n"
            . 'give it a permission callback.'
        );
    }

    /** A stale entry means the list stopped describing the plugin. */
    public function test_the_public_list_has_no_routes_that_no_longer_exist(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::PUBLIC_ROUTES), array_keys($this->routes()))),
            'PUBLIC_ROUTES names a route the plugin no longer registers.'
        );
    }

    /**
     * The one that catches a callback which never refuses anyone: dispatch each
     * guarded route with no user and require a refusal. Naming a callback is
     * not the same as being protected by it.
     */
    public function test_every_guarded_route_turns_an_anonymous_caller_away(): void
    {
        wp_set_current_user(0);

        $reachable = [];
        foreach ($this->routes() as $route => $handlers) {
            if (isset(self::PUBLIC_ROUTES[$route])) {
                continue;
            }

            // Concrete values for the path parameters, so dispatch reaches the
            // permission callback instead of failing to match the route.
            $url = preg_replace('/\(\?P<\w+>[^)]*\)/', '1', $route);

            foreach ($handlers as $handler) {
                foreach (array_keys(array_filter($handler['methods'] ?? [])) as $method) {
                    $response = rest_do_request(new \WP_REST_Request($method, $url));
                    $status   = $response->get_status();

                    // 401/403 is the refusal. 404 means the id did not resolve,
                    // which is a refusal too. Anything 2xx means it ran.
                    if ($status < 400) {
                        $reachable[] = "{$method} {$route} -> {$status}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $reachable,
            "These routes answered an anonymous caller instead of refusing:\n"
            . implode("\n", $reachable)
        );
    }
}
