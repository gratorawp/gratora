<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * Registration is open, so anyone can hold a portal session and loop the
 * statement route. Each call re-runs the year's donation and refund queries and
 * a full Dompdf parse and layout with the document in memory. The byte-for-byte
 * equivalent behind the receipt download is already budgeted, with a comment
 * calling an unmetered render an amplifier pointed at this site.
 */
final class StatementRenderIsBudgetedTest extends IntegrationTestCase
{
    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Test mode multiplies every ceiling tenfold, which would take this
        // route past any loop a test can afford to run.
        update_option('fundkit_gateway_config', ['test_mode' => false]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['fundkit_donor_session']);
        parent::tearDown();
    }

    private function signIn(): Donor
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('stmt-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        $this->csrf = bin2hex(random_bytes(8));
        $_COOKIE['fundkit_donor_session'] = $this->portalSession((int) $donor->id, $this->csrf);

        return $donor;
    }

    private function statement(): int
    {
        $req = new WP_REST_Request('GET', '/fundkit/v1/portal/annual-statement/2026');
        $req->set_header('X-FundKit-Csrf', $this->csrf);

        return rest_do_request($req)->get_status();
    }

    public function test_a_loop_is_stopped_before_it_costs_the_site(): void
    {
        $this->signIn();

        $statuses = [];
        for ($i = 0; $i < 25; $i++) {
            $statuses[] = $this->statement();
        }

        $this->assertContains(429, $statuses, 'an unmetered render is an amplifier pointed at this site');
    }

    /** And a donor pulling a few years of statements is well clear of it. */
    public function test_a_handful_of_statements_is_not_refused(): void
    {
        $this->signIn();

        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->statement());
        }
    }
}
