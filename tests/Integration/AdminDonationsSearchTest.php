<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

final class AdminDonationsSearchTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDonors();
    }

    public function test_search_by_reference_substring(): void
    {
        $items = $this->search('00002');

        $this->assertCount(1, $items);
        $this->assertStringContainsString('00002', $items[0]['reference']);
    }

    public function test_search_by_donor_first_name(): void
    {
        $items = $this->search('Sarah');

        $this->assertGreaterThanOrEqual(1, count($items));
        $names = array_column(array_column($items, 'donor'), 'name');
        $this->assertContains('Sarah Müller', $names);
    }

    public function test_search_by_donor_last_name(): void
    {
        $items = $this->search('Parker');
        $names = array_column(array_column($items, 'donor'), 'name');
        $this->assertContains('James Parker', $names);
    }

    public function test_search_is_substring_on_name(): void
    {
        $items = $this->search('par'); // substring of "Parker"
        $names = array_column(array_column($items, 'donor'), 'name');
        $this->assertContains('James Parker', $names);
    }

    public function test_search_by_exact_email(): void
    {
        $items = $this->search('luca.rossi@example.it');
        $this->assertCount(1, $items);
        $this->assertSame('Luca Rossi', $items[0]['donor']['name']);
    }

    public function test_search_email_is_case_insensitive(): void
    {
        // emailHash normalises (lowercase + trim) before hashing.
        $items = $this->search('LUCA.Rossi@example.it');
        $this->assertCount(1, $items);
        $this->assertSame('Luca Rossi', $items[0]['donor']['name']);
    }

    public function test_unknown_search_term_returns_empty_set(): void
    {
        $items = $this->search('XYZNO_MATCH_HERE');
        $this->assertSame([], $items);
    }

    public function test_empty_search_returns_everything(): void
    {
        $items = $this->search('');
        $this->assertGreaterThanOrEqual(4, count($items));
    }

    public function test_status_filter_combines_with_search(): void
    {
        // Search "Sarah" → finds Sarah Müller, who has 1 PAID donation.
        $res = $this->request(['search' => 'Sarah', 'status' => 'paid']);
        $this->assertSame(200, $res->get_status());
        $items = $res->get_data();
        $this->assertCount(1, $items);
        $this->assertSame('paid', $items[0]['status']);
        $this->assertSame('Sarah Müller', $items[0]['donor']['name']);
    }

    /** @return array<int, array<string,mixed>> */
    private function search(string $term): array
    {
        return $this->request(['search' => $term])->get_data();
    }

    private function request(array $params): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donations');
        $req->set_query_params(array_merge(['page' => 1, 'per_page' => 25], $params));
        return rest_do_request($req);
    }

    private function seedDonors(): void
    {
        $fixtures = [
            ['sarah.mueller@example.de',   'Sarah',  'Müller'],
            ['james.parker@example.co.uk', 'James',  'Parker'],
            ['marie.dupont@example.fr',    'Marie',  'Dupont'],
            ['luca.rossi@example.it',      'Luca',   'Rossi'],
        ];
        foreach ($fixtures as [$email, $first, $last]) {
            $req = new WP_REST_Request('POST', '/dono/v1/donations');
            $req->set_header('content-type', 'application/json');
            $req->set_body(json_encode([
                'email' => $email, 'amount_cents' => 5000, 'currency' => 'EUR',
                'gateway' => 'offline',
                'profile' => ['first_name' => $first, 'last_name' => $last, 'country' => 'US'],
            ]));
            $reference = rest_do_request($req)->get_data()['reference'];

            $req2 = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
            $req2->set_header('content-type', 'application/json');
            $req2->set_body('{}');
            rest_do_request($req2);
        }
    }
}
