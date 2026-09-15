<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Rest\CorsPolicy;
use WP_REST_Request;

/**
 * Reject foreign browser origins despite WordPress’s permissive REST CORS, which would expose
 * gateway replies and let attackers spend visitors’ IP quotas.
 */
final class CrossOriginDonationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ORIGIN']);
        remove_all_filters('allowed_http_origins');
        parent::tearDown();
    }

    private function submit(): int
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'origin-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'frequency'    => 'one_time',
        ]));

        return rest_do_request($req)->get_status();
    }

    public function test_the_sites_own_page_is_allowed(): void
    {
        $_SERVER['HTTP_ORIGIN'] = home_url();

        $this->assertNotSame(403, $this->submit(), 'a donation from this site must never be refused');
    }

    /**
     * Core's get_allowed_http_origins compares hosts and drops the port, with a
     * "@todo Preserve port?" where it does. A site served on an explicit port
     * therefore fails its own check, and every donor fails with it. Caught only
     * by driving a real site: the test environment has no port, so relying on
     * core's helper alone passes here and refuses every donation in production.
     */
    public function test_a_site_served_on_a_port_allows_its_own_page(): void
    {
        $home = static fn (): string => 'http://localhost:10075';
        add_filter('home_url', $home);
        add_filter('site_url', $home);

        try {
            $_SERVER['HTTP_ORIGIN'] = 'http://localhost:10075';
            $this->assertNotSame(403, $this->submit(), 'the port is part of the origin, and part of this site');

            $_SERVER['HTTP_ORIGIN'] = 'http://localhost:9999';
            $this->assertSame(403, $this->submit(), 'a different port is a different origin');
        } finally {
            remove_filter('home_url', $home);
            remove_filter('site_url', $home);
        }
    }

    /**
     * No browser puts the scheme's default port in Origin, so a home_url that
     * carries one builds a string nothing can match, and the site refuses every
     * donor it has.
     */
    public function test_a_home_url_carrying_the_default_port_still_allows_its_own_donors(): void
    {
        $home = static fn (): string => 'https://example.org:443';
        add_filter('home_url', $home);
        add_filter('site_url', $home);

        try {
            $_SERVER['HTTP_ORIGIN'] = 'https://example.org';
            $this->assertNotSame(403, $this->submit(), 'the site must accept the origin its own pages send');
        } finally {
            remove_filter('home_url', $home);
            remove_filter('site_url', $home);
        }
    }

    public function test_a_request_with_no_origin_is_allowed(): void
    {
        unset($_SERVER['HTTP_ORIGIN']);

        $this->assertNotSame(403, $this->submit());
    }

    public function test_a_page_this_site_did_not_serve_is_refused(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://malvertising.example';

        $this->assertSame(403, $this->submit(), 'a stranger\'s page must not mint client secrets from its visitors');
    }

    /**
     * A decoupled front end is a real deployment, and it must have a way in
     * that is not "turn the check off". Core's own filter is that way.
     */
    public function test_a_decoupled_front_end_can_be_allowed_by_filter(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example';
        $this->assertSame(403, $this->submit(), 'not allowed until the site says so');

        add_filter('allowed_http_origins', static function (array $origins): array {
            $origins[] = 'https://app.example';

            return $origins;
        });

        $this->assertNotSame(403, $this->submit(), 'the documented filter must open it');
    }

    private function allow(string $origin): void
    {
        add_filter('allowed_http_origins', static function (array $origins) use ($origin): array {
            $origins[] = $origin;

            return $origins;
        });
    }

    /**
     * The same deployment as the case above, read from the response side: the
     * filter is the way in, so it has to survive the strip as well as the gate.
     */
    public function test_a_decoupled_front_end_keeps_its_reflected_headers(): void
    {
        $policy = new CorsPolicy();

        $this->assertArrayNotHasKey(
            'Access-Control-Allow-Origin',
            $policy->headersFor('https://app.example'),
            'not allowed until the site says so'
        );

        $this->allow('https://app.example');
        $headers = $policy->headersFor('https://app.example');

        $this->assertSame('https://app.example', $headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials']);
    }

    public function test_the_sites_own_page_keeps_its_reflected_headers(): void
    {
        $headers = (new CorsPolicy())->headersFor(untrailingslashit(home_url()));

        $this->assertSame(untrailingslashit(home_url()), $headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials']);
    }

    public function test_a_site_served_on_a_port_keeps_its_own_reflected_headers(): void
    {
        $home = static fn (): string => 'http://localhost:10075';
        add_filter('home_url', $home);
        add_filter('site_url', $home);

        try {
            $policy = new CorsPolicy();

            $this->assertSame(
                'http://localhost:10075',
                $policy->headersFor('http://localhost:10075')['Access-Control-Allow-Origin'],
                'the port is part of the origin, and part of this site'
            );
            $this->assertArrayNotHasKey(
                'Access-Control-Allow-Origin',
                $policy->headersFor('http://localhost:9999'),
                'a different port is a different origin'
            );
        } finally {
            remove_filter('home_url', $home);
            remove_filter('site_url', $home);
        }
    }

    /**
     * What the strip is for: core reflects the caller with credentials, so
     * without it a stranger's page reads the client_secret out of a 201.
     */
    public function test_a_page_this_site_did_not_serve_is_reflected_nothing(): void
    {
        $headers = (new CorsPolicy())->headersFor('https://malvertising.example');

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    public function test_a_request_with_no_origin_is_reflected_nothing(): void
    {
        $headers = (new CorsPolicy())->headersFor('');

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    /**
     * A cache that keeps one donor's answer for the next donor's origin is the
     * strip undone, so the header goes out whichever way the branch fell.
     */
    public function test_vary_origin_is_sent_on_every_branch(): void
    {
        $policy = new CorsPolicy();
        $this->allow('https://app.example');

        foreach (['', untrailingslashit(home_url()), 'https://app.example', 'https://malvertising.example'] as $origin) {
            $this->assertSame('Origin', $policy->headersFor($origin)['Vary'] ?? null, $origin);
        }
    }
}
