<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Donors\ConsentService;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Rest\Portal\PortalController;
use WP_REST_Request;

/**
 * Three ways a donor's own data outlived the moment it was for.
 */
final class PortalPrivacyLeaksTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // WP_UnitTestCase rolls back every hook a previous test added, so by
        // the time a test runs nothing is listening on rest_api_init any more
        // and the routes' own filters are gone with it.
        add_action('rest_api_init', static function (): void {
            Plugin::instance()->container->get(PortalController::class)->registerRoutes();
        });

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    private function paidDonation(string $email, array $consents = []): string
    {
        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode(array_filter([
            'email'        => $email,
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
            'consents'     => $consents ?: null,
        ])));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/fundkit/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }

    private function donorFor(string $email): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)->findByEmail($email);
    }


    /** @return array<string,string> */
    private function portalHeaders(int $donorId, string $route): array
    {
        $_COOKIE['fundkit_donor_session'] = $this->portalSession($donorId, 'tok');

        try {
            $res = rest_do_request(new WP_REST_Request('GET', $route));
            $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

            return (array) $res->get_headers();
        } finally {
            unset($_COOKIE['fundkit_donor_session']);
        }
    }

    public function test_the_profile_route_forbids_a_shared_cache_from_storing_it(): void
    {
        $email = 'cache-' . uniqid() . '@example.test';
        $this->paidDonation($email);
        $donor = $this->donorFor($email);

        $headers = $this->portalHeaders((int) $donor->id, '/fundkit/v1/portal/profile');

        $this->assertArrayHasKey('Cache-Control', $headers, 'a CDN may store the decrypted email and serve it to the next visitor');
        $this->assertStringContainsString('no-store', (string) $headers['Cache-Control']);
        $this->assertStringContainsString('private', (string) $headers['Cache-Control']);
        $this->assertSame('Cookie', (string) ($headers['Vary'] ?? ''), 'without this a cache keys on the URL alone');
    }

    public function test_the_donations_list_is_covered_too(): void
    {
        $email = 'cache-list-' . uniqid() . '@example.test';
        $this->paidDonation($email);
        $donor = $this->donorFor($email);

        $headers = $this->portalHeaders((int) $donor->id, '/fundkit/v1/portal/donations');

        $this->assertStringContainsString('no-store', (string) ($headers['Cache-Control'] ?? ''));
    }

    public function test_a_non_portal_route_is_left_alone(): void
    {
        $res = apply_filters(
            'rest_request_after_callbacks',
            new \WP_REST_Response([], 200),
            [],
            new WP_REST_Request('GET', '/fundkit/v1/campaigns')
        );

        $this->assertArrayNotHasKey('Cache-Control', $res->get_headers());
    }


    private function setPurposes(array $purposes): void
    {
        update_option('fundkit_consents', ['purposes' => $purposes]);
    }

    private function grantedNow(int $donorId, string $key): ?bool
    {
        $latest = Plugin::instance()->container->get(ConsentService::class)->latestByPurpose($donorId);

        return isset($latest[$key]) ? (bool) $latest[$key]->granted : null;
    }

    public function test_a_second_donation_does_not_withdraw_a_consent_the_donor_gave(): void
    {
        $this->setPurposes([
            ['key' => 'newsletter', 'label' => 'Newsletter', 'required' => false, 'default' => false, 'version' => 1],
        ]);

        $email = 'consent-' . uniqid() . '@example.test';
        $this->paidDonation($email, ['newsletter' => true]);
        $donor = $this->donorFor($email);
        $this->assertTrue($this->grantedNow((int) $donor->id, 'newsletter'), 'fixture: the donor opted in');

        // The box renders unticked next time, so the donor leaves it alone.
        $this->paidDonation($email, ['newsletter' => false]);

        $this->assertTrue(
            $this->grantedNow((int) $donor->id, 'newsletter'),
            'the donor is now marked as having withdrawn a consent they never withdrew'
        );
    }

    public function test_unticking_a_box_that_renders_ticked_does_withdraw(): void
    {
        $this->setPurposes([
            ['key' => 'updates', 'label' => 'Updates', 'required' => false, 'default' => true, 'version' => 1],
        ]);

        $email = 'consent-optout-' . uniqid() . '@example.test';
        $this->paidDonation($email, ['updates' => true]);
        $donor = $this->donorFor($email);

        $this->paidDonation($email, ['updates' => false]);

        $this->assertFalse($this->grantedNow((int) $donor->id, 'updates'));
    }

    public function test_a_first_donation_still_records_a_declined_box(): void
    {
        $this->setPurposes([
            ['key' => 'newsletter', 'label' => 'Newsletter', 'required' => false, 'default' => false, 'version' => 1],
        ]);

        $email = 'consent-first-' . uniqid() . '@example.test';
        $this->paidDonation($email, ['newsletter' => false]);

        $this->assertFalse($this->grantedNow((int) $this->donorFor($email)->id, 'newsletter'));
    }

    public function test_a_donation_can_still_grant(): void
    {
        $this->setPurposes([
            ['key' => 'newsletter', 'label' => 'Newsletter', 'required' => false, 'default' => false, 'version' => 1],
        ]);

        $email = 'consent-grant-' . uniqid() . '@example.test';
        $this->paidDonation($email, ['newsletter' => false]);
        $this->paidDonation($email, ['newsletter' => true]);

        $this->assertTrue($this->grantedNow((int) $this->donorFor($email)->id, 'newsletter'));
    }


    public function test_the_erasure_audit_row_keeps_no_handle_back_to_the_person(): void
    {
        $_SERVER['REMOTE_ADDR']     = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (the erased donor)';
        update_option('fundkit_privacy_settings', ['anonymize_ips' => true]);

        $email = 'erased-' . uniqid() . '@example.test';
        $this->paidDonation($email);
        $donor = $this->donorFor($email);

        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        $row = Event::query()
            ->where('type', 'donor.redacted')
            ->where('donor_id', (int) $donor->id)
            ->get();

        $this->assertNotNull($row, 'the audit row is kept on purpose');
        $this->assertNull($row->ip_hash, 'the erased donor\'s IP hash outlived their erasure');
        $this->assertNull($row->user_agent_hash, 'and so did their user-agent hash');
        $this->assertNull($row->session_hash);
    }

    public function test_the_erasure_audit_row_still_names_the_actor(): void
    {
        $email = 'erased-actor-' . uniqid() . '@example.test';
        $this->paidDonation($email);
        $donor = $this->donorFor($email);

        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        $row = Event::query()->where('type', 'donor.redacted')->where('donor_id', (int) $donor->id)->get();

        $this->assertNotSame('', (string) ($row->payload['by'] ?? ''));
    }

    public function test_a_donation_event_still_carries_its_handles(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (an ordinary visitor)';
        $email = 'handles-' . uniqid() . '@example.test';
        $this->paidDonation($email);

        $row = Event::query()
            ->where('donor_id', (int) $this->donorFor($email)->id)
            ->where('type', 'donation.completed')
            ->get();

        $this->assertNotNull($row);
        $this->assertNotNull($row->user_agent_hash, 'analytics still needs to tell two visitors apart');
    }
}
