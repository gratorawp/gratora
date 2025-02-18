<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationTribute;
use Dono\Donations\DonationTributeRepository;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Tribute blocks let admins register custom type ids beyond the built-in
 * honor/memorial pair (e.g. "celebrate", "graduation", "anniversary"). Verifies
 * the full round-trip: REST submit → persist with the exact id → notify_email
 * encrypts → portal endpoint surfaces the same id back.
 */
final class TributeCustomTypeIdTest extends IntegrationTestCase
{
    public function test_custom_tribute_type_id_round_trips_through_create_and_storage(): void
    {
        $reference = $this->driveDonation([
            'tribute' => [
                'type'         => 'celebrate',
                'name'         => 'Anna Milestone',
                'message'      => 'For your 50th!',
                'notify_email' => 'anna@example.com',
            ],
        ]);

        $donation = Plugin::instance()->container
            ->get(\Dono\Donations\DonationRepository::class)
            ->findByReference($reference);
        $this->assertNotNull($donation);

        /** @var DonationTributeRepository $tributeRepo */
        $tributeRepo = Plugin::instance()->container->get(DonationTributeRepository::class);
        $row = $tributeRepo->forDonation((int) $donation->id);

        $this->assertNotNull($row, 'A tribute row is persisted for the donation');
        $this->assertSame('celebrate', $row->type, 'Custom type id is stored verbatim (not coerced to honor/memorial)');
        $this->assertSame('Anna Milestone', $row->name);
        $this->assertNotNull($row->notify_email_encrypted, 'Notify email is encrypted, not stored in plaintext');
        $this->assertSame('anna@example.com', $tributeRepo->decryptedNotifyEmail($row));
        $this->assertSame('For your 50th!', $tributeRepo->decryptedMessage($row));
    }

    public function test_arbitrary_type_id_is_not_silently_rewritten(): void
    {
        // No allowlist: type ids come from the form's block attribute, so any
        // string the admin defines is honoured. This locks the contract: if a
        // future change adds enum validation, this test fails and prompts a
        // migration story for old non-default types.
        $reference = $this->driveDonation([
            'tribute' => [
                'type' => 'anniversary',
                'name' => 'Pat & Sam',
            ],
        ]);

        $donation = Plugin::instance()->container
            ->get(\Dono\Donations\DonationRepository::class)
            ->findByReference($reference);
        $row = Plugin::instance()->container
            ->get(DonationTributeRepository::class)
            ->forDonation((int) $donation->id);

        $this->assertSame('anniversary', $row->type);
    }

    public function test_portal_donations_payload_surfaces_custom_type_id(): void
    {
        // Drive a donation with a custom type, then forge a portal session
        // directly (PortalSession::open() calls setcookie(), which trips
        // "headers already sent" inside PHPUnit). We mirror its persistence
        // shape so PortalSession::readSession can find us.
        $reference = $this->driveDonation([
            'tribute' => ['type' => 'graduation', 'name' => 'Class of 2026'],
        ]);
        $donation = Plugin::instance()->container
            ->get(\Dono\Donations\DonationRepository::class)
            ->findByReference($reference);

        // Sanity: tribute row exists for the donation before we hit the API.
        $tributeRow = Plugin::instance()->container
            ->get(DonationTributeRepository::class)
            ->forDonation((int) $donation->id);
        $this->assertNotNull($tributeRow, 'Tribute row was persisted for the donation');
        $this->assertSame('graduation', $tributeRow->type);

        $sid = bin2hex(random_bytes(32));
        set_transient(
            'dono_portal_' . hash('sha256', $sid),
            ['donor_id' => (int) $donation->donor_id, 'csrf' => bin2hex(random_bytes(8))],
            HOUR_IN_SECONDS
        );
        $_COOKIE['dono_donor_session'] = $sid;

        try {
            // The donationsList payload is intentionally minimal (no tribute
            // join); donationShow is where the tribute payload surfaces. Hit
            // both so we cover the list endpoint AND assert the type id is in
            // the detail payload.
            $listReq = new WP_REST_Request('GET', '/dono/v1/portal/donations');
            $listRes = rest_do_request($listReq);
            $this->assertSame(200, $listRes->get_status());
            $references = array_column((array) $listRes->get_data(), 'reference');
            $this->assertContains($reference, $references, 'Donation surfaces in portal list');

            $showReq = new WP_REST_Request('GET', "/dono/v1/portal/donations/{$reference}");
            $showRes = rest_do_request($showReq);
            $this->assertSame(200, $showRes->get_status());
            $payload = $showRes->get_data();
            $this->assertSame('graduation', $payload['tribute']['type'] ?? null);
            $this->assertSame('Class of 2026', $payload['tribute']['name'] ?? null);
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }
    }

    private function driveDonation(array $extras = []): string
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body((string) wp_json_encode(array_merge([
            'email'        => 'tribute-donor@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Tribute', 'last_name' => 'Donor'],
        ], $extras)));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);
        $this->runPendingAsyncJobs();

        return $reference;
    }

}
