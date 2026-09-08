<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

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
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
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
}
