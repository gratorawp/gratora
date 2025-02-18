<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A donation snapshots the name as given for that donation. The donor record
 * stays the canonical (lock-on-first-write) identity, so a later donation
 * under a different name is preserved on the donation and used by receipts.
 */
final class DonationDonorNameTest extends IntegrationTestCase
{
    private function postDonation(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function confirm(string $reference): void
    {
        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        rest_do_request($req);
    }

    private function donation(string $reference): Donation
    {
        return Plugin::instance()->container
            ->get(DonationRepository::class)
            ->findByReference($reference);
    }

    public function test_donation_snapshots_the_name_as_given(): void
    {
        $reference = $this->postDonation([
            'email'        => 'snap@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ])->get_data()['reference'];

        $d = $this->donation($reference);
        $this->assertSame('Ada', $d->donor_first_name);
        $this->assertSame('Lovelace', $d->donor_last_name);
    }

    public function test_later_name_is_kept_on_the_donation_though_donor_record_is_locked(): void
    {
        // First donation establishes the donor record (lock-on-first-write).
        $first = $this->postDonation([
            'email'        => 'shared@example.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Bob', 'last_name' => 'Roe'],
        ])->get_data()['reference'];

        // Same email, different name (spouse / married name / correction).
        $second = $this->postDonation([
            'email'        => 'shared@example.com',
            'amount_cents' => 2000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Robert', 'last_name' => 'Roe'],
        ])->get_data()['reference'];

        $d1 = $this->donation($first);
        $d2 = $this->donation($second);

        $donor = Donor::query()->where('id', $d2->donor_id)->get();
        $this->assertSame($d1->donor_id, $d2->donor_id, 'same email = same donor');
        $this->assertSame('Bob', $donor->first_name, 'donor record stays locked to the first name');

        $this->assertSame('Bob', $d1->donor_first_name);
        $this->assertSame('Robert', $d2->donor_first_name, 'the later name is preserved on its donation');
    }

    public function test_receipt_uses_the_name_given_for_that_donation(): void
    {
        // Lock the donor record to "Bob Roe".
        $this->postDonation([
            'email'        => 'receipt@example.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Bob', 'last_name' => 'Roe'],
        ]);

        // A later donation given as "Robert Roe".
        $reference = $this->postDonation([
            'email'        => 'receipt@example.com',
            'amount_cents' => 4200,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Robert', 'last_name' => 'Roe'],
        ])->get_data()['reference'];

        $mails = $this->captureMails();
        $this->confirm($reference);
        $this->runPendingAsyncJobs();

        $receipt = null;
        foreach ($mails as $m) {
            if (str_contains((string) $m['message'], $reference)) {
                $receipt = $m;
                break;
            }
        }
        $this->assertNotNull($receipt, 'a receipt email was sent for the confirmed donation');
        $this->assertStringContainsString('Hi Robert,', (string) $receipt['message']);
        $this->assertStringNotContainsString('Hi Bob,', (string) $receipt['message']);
    }

    public function test_admin_detail_exposes_the_given_name(): void
    {
        $reference = $this->postDonation([
            'email'        => 'admin@example.com',
            'amount_cents' => 1500,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Grace', 'last_name' => 'Hopper'],
        ])->get_data()['reference'];

        $res = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$reference}"));
        $this->assertSame(200, $res->get_status());
        $this->assertSame('Grace Hopper', $res->get_data()['donation']['donor_name_given']);
    }

    public function test_erasure_clears_the_donation_name_snapshot(): void
    {
        $reference = $this->postDonation([
            'email'        => 'forget-name@example.com',
            'amount_cents' => 9000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Alan', 'last_name' => 'Turing'],
        ])->get_data()['reference'];

        $d = $this->donation($reference);
        $this->assertSame('Alan', $d->donor_first_name);

        $donor = Donor::query()->where('id', $d->donor_id)->get();
        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        $after = $this->donation($reference);
        $this->assertNull($after->donor_first_name, 'name snapshot cleared on erasure');
        $this->assertNull($after->donor_last_name);
        $this->assertSame($reference, $after->reference, 'donation record itself retained');
    }

    public function test_dsar_export_includes_the_given_name(): void
    {
        $reference = $this->postDonation([
            'email'        => 'dsar-name@example.com',
            'amount_cents' => 2500,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Edsger', 'last_name' => 'Dijkstra'],
        ])->get_data()['reference'];

        $donorId  = (int) $this->donation($reference)->donor_id;
        $request  = new WP_REST_Request('GET', "/dono/v1/admin/donors/{$donorId}/export");
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        ob_start();
        apply_filters('rest_pre_serve_request', false, $response, $request, rest_get_server());
        $bundle = json_decode((string) ob_get_clean(), true);

        $entry = null;
        foreach ($bundle['donations'] ?? [] as $row) {
            if (($row['reference'] ?? '') === $reference) {
                $entry = $row;
                break;
            }
        }
        $this->assertNotNull($entry, 'donation present in DSAR export');
        $this->assertSame('Edsger Dijkstra', $entry['donor_name_given']);
    }
}
