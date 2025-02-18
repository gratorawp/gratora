<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * Exercises GET /wp-json/dono/v1/admin/donors.
 *
 * Donor records get materialized as a side-effect of POSTing a donation -
 * we drive them through the existing public donations endpoint rather than
 * inserting directly, so the test matches production write paths (hashed
 * email + encrypted PII + profile fields all flow through DonorService).
 */
final class AdminDonorsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDonors();
    }

    public function test_index_lists_donors_with_decrypted_email(): void
    {
        $res = $this->get([]);
        $this->assertSame(200, $res->get_status());

        $items = $res->get_data();
        $this->assertSame('4', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertCount(4, $items);

        $names  = array_column($items, 'name');
        $emails = array_column($items, 'email');
        $this->assertContains('Sarah Müller',        $names);
        $this->assertContains('luca.rossi@example.it', $emails,
            'Email should come back decrypted for admin eyes');
    }

    public function test_search_by_first_name_substring(): void
    {
        $res = $this->get(['search' => 'Sarah']);
        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertSame('Sarah Müller', $res->get_data()[0]['name']);
    }

    public function test_search_by_exact_email_case_insensitive(): void
    {
        $res = $this->get(['search' => 'LUCA.Rossi@example.it']);
        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertSame('Luca Rossi', $res->get_data()[0]['name']);
    }

    public function test_search_with_no_matches_returns_empty(): void
    {
        $res = $this->get(['search' => 'NoSuchPersonHere']);
        $this->assertSame('0', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertSame([], $res->get_data());
    }

    public function test_country_filter(): void
    {
        $res = $this->get(['country' => 'DE']);
        $this->assertSame('1', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertSame('DE', $res->get_data()[0]['country']);
    }

    public function test_sort_by_total_donated_descending(): void
    {
        // Bump Luca's totals so we get a deterministic order without re-seeding.
        self::$wpdb->query(
            "UPDATE " . self::$prefix . "dono_donors SET total_donated_cents = 99999 WHERE first_name = 'Luca'"
        );

        $items = $this->get([
            'orderby' => 'total_donated_cents',
            'order'   => 'desc',
        ])->get_data();

        $this->assertSame('Luca Rossi', $items[0]['name']);
    }

    public function test_pagination_per_page(): void
    {
        $res = $this->get(['per_page' => 2, 'page' => 1]);
        $this->assertSame('4', $res->get_headers()['X-WP-Total'] ?? '0');
        $this->assertCount(2, $res->get_data());
    }

    private function get(array $params): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donors');
        $req->set_query_params($params);
        return rest_do_request($req);
    }

    private function seedDonors(): void
    {
        $fixtures = [
            ['sarah.mueller@example.de',   'Sarah',  'Müller', 'DE'],
            ['james.parker@example.co.uk', 'James',  'Parker', 'GB'],
            ['marie.dupont@example.fr',    'Marie',  'Dupont', 'FR'],
            ['luca.rossi@example.it',      'Luca',   'Rossi',  'IT'],
        ];
        foreach ($fixtures as [$email, $first, $last, $country]) {
            $req = new WP_REST_Request('POST', '/dono/v1/donations');
            $req->set_header('content-type', 'application/json');
            $req->set_body(json_encode([
                'email'        => $email,
                'amount_cents' => 5000,
                'currency'     => 'EUR',
                'gateway'      => 'offline',
                'profile'      => ['first_name' => $first, 'last_name' => $last, 'country' => $country],
            ]));
            $reference = rest_do_request($req)->get_data()['reference'];

            // Confirm so last_donation_at + totals reflect a real flow.
            $req2 = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
            $req2->set_header('content-type', 'application/json');
            $req2->set_body('{}');
            rest_do_request($req2);
        }
    }
}
