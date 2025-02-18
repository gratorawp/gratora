<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Donor-submitted custom form-field values: encrypted at rest on the
 * donation, decrypted for the admin detail view, and cleared on donor
 * erasure while the financial donation record is retained.
 */
final class DonationCustomDataTest extends IntegrationTestCase
{
    private function postDonation(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    private function donation(string $reference): Donation
    {
        return Plugin::instance()->container
            ->get(DonationRepository::class)
            ->findByReference($reference);
    }

    public function test_custom_values_persist_encrypted_and_round_trip(): void
    {
        $custom = [
            'hear_about_us' => 'Newsletter',
            'tshirt_size'   => 'L',
            'topics'        => ['climate', 'water'],
        ];

        $res = $this->postDonation([
            'email'        => 'c@example.com',
            'amount_cents' => 4200,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'custom'       => $custom,
        ]);
        $this->assertSame(201, $res->get_status());
        $reference = $res->get_data()['reference'];

        // Stored as ciphertext, never plaintext.
        $row = self::$wpdb->get_row(self::$wpdb->prepare(
            'SELECT custom_data_encrypted FROM ' . self::$prefix . 'dono_donations WHERE reference = %s',
            $reference
        ));
        $this->assertNotEmpty($row->custom_data_encrypted);
        $this->assertStringNotContainsString('Newsletter', $row->custom_data_encrypted);

        // Service decrypts back to exactly what the donor submitted.
        $svc = Plugin::instance()->container->get(DonationService::class);
        $this->assertSame($custom, $svc->decryptCustomData($this->donation($reference)));
    }

    public function test_admin_detail_exposes_decrypted_custom_data(): void
    {
        $custom    = ['referred_by' => 'Jane Doe'];
        $reference = $this->postDonation([
            'email'        => 'd@example.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'custom'       => $custom,
        ])->get_data()['reference'];

        $res = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$reference}"));
        $this->assertSame(200, $res->get_status());
        $this->assertSame($custom, $res->get_data()['donation']['custom_data']);
    }

    public function test_donation_without_custom_stores_null(): void
    {
        $reference = $this->postDonation([
            'email'        => 'e@example.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
        ])->get_data()['reference'];

        $row = self::$wpdb->get_row(self::$wpdb->prepare(
            'SELECT custom_data_encrypted FROM ' . self::$prefix . 'dono_donations WHERE reference = %s',
            $reference
        ));
        $this->assertNull($row->custom_data_encrypted);

        $svc = Plugin::instance()->container->get(DonationService::class);
        $this->assertSame([], $svc->decryptCustomData($this->donation($reference)));
    }

    public function test_donor_erasure_clears_custom_data_but_keeps_donation(): void
    {
        $reference = $this->postDonation([
            'email'        => 'forget-me@example.com',
            'amount_cents' => 7000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'custom'       => ['note' => 'sensitive answer'],
        ])->get_data()['reference'];

        $donation = $this->donation($reference);
        $this->assertNotNull($donation->custom_data_encrypted);

        $container = Plugin::instance()->container;
        $donor     = Donor::query()->where('id', $donation->donor_id)->get();
        $container->get(DonorService::class)->redact($donor);

        $after = $this->donation($reference);
        $this->assertNull($after->custom_data_encrypted, 'custom answers cleared on erasure');
        $this->assertSame($reference, $after->reference, 'financial donation record is retained');
        $this->assertSame(7000, (int) $after->amount_cents);

        $svc = $container->get(DonationService::class);
        $this->assertSame([], $svc->decryptCustomData($after));
    }

    private function exportBundle(int $donorId): array
    {
        // exportPersonalData() streams the JSON via a rest_pre_serve_request
        // closure (bound to this request's route) and returns a null-body
        // response. rest_do_request runs the controller and registers that
        // closure; fire it with the same request to capture the bytes.
        $request  = new WP_REST_Request('GET', "/dono/v1/admin/donors/{$donorId}/export");
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        ob_start();
        apply_filters('rest_pre_serve_request', false, $response, $request, rest_get_server());
        $body = (string) ob_get_clean();

        $bundle = json_decode($body, true);
        $this->assertIsArray($bundle, 'DSAR export must be a JSON object');
        return $bundle;
    }

    private function exportedDonation(array $bundle, string $reference): array
    {
        foreach ($bundle['donations'] ?? [] as $row) {
            if (($row['reference'] ?? '') === $reference) {
                return $row;
            }
        }
        $this->fail("Donation {$reference} missing from DSAR export");
    }

    public function test_dsar_export_includes_decrypted_custom_data(): void
    {
        $custom    = ['hear_about_us' => 'Friend', 'amount_pledged' => '250'];
        $reference = $this->postDonation([
            'email'        => 'dsar@example.com',
            'amount_cents' => 3000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'custom'       => $custom,
        ])->get_data()['reference'];

        $donorId = (int) $this->donation($reference)->donor_id;
        $entry   = $this->exportedDonation($this->exportBundle($donorId), $reference);

        $this->assertSame($custom, $entry['custom_data']);
    }

    public function test_dsar_export_reports_empty_custom_data_after_erasure(): void
    {
        $reference = $this->postDonation([
            'email'        => 'dsar-forget@example.com',
            'amount_cents' => 9000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'custom'       => ['note' => 'to be erased'],
        ])->get_data()['reference'];

        $donation = $this->donation($reference);
        $donor    = Donor::query()->where('id', $donation->donor_id)->get();
        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        $entry = $this->exportedDonation(
            $this->exportBundle((int) $donation->donor_id),
            $reference
        );
        $this->assertSame([], $entry['custom_data'], 'DSAR access reflects the erasure');
    }
}
