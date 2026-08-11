<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A partially refunded donation exports with money on both sides of it: the
 * amount that came in, and the amount that went back. Summing the file without
 * the second one overstates revenue by every refund.
 */
final class DonationExportRefundColumnTest extends IntegrationTestCase
{
    public function test_a_partial_refund_states_the_refunded_amount(): void
    {
        $donation = $this->paidDonation(50000);
        $this->donationService()->refund($donation, 40000, 'partial - one item returned');

        [$header, $row] = $this->headerAndRow(
            $this->serveBody('/dono/v1/admin/donations/export.csv'),
            (string) $donation->reference,
        );

        $refunded = array_search('Refunded', $header, true);
        $this->assertNotFalse($refunded, 'the export states what went back');
        $this->assertSame('400.00', $row[$refunded]);
        $this->assertSame('partial_refund', $row[(int) array_search('Status', $header, true)]);
    }

    public function test_an_unrefunded_donation_states_zero(): void
    {
        $donation = $this->paidDonation(50000);

        [$header, $row] = $this->headerAndRow(
            $this->serveBody('/dono/v1/admin/donations/export.csv'),
            (string) $donation->reference,
        );

        $this->assertSame('0.00', $row[(int) array_search('Refunded', $header, true)]);
    }

    private function paidDonation(int $cents): Donation
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => $cents,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'country' => 'US'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $this->donations()->findByReference($reference);
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private function headerAndRow(string $csv, string $reference): array
    {
        $lines  = array_values(array_filter(preg_split('/\r?\n/', trim($csv)) ?: []));
        $header = str_getcsv(ltrim((string) array_shift($lines), "\xEF\xBB\xBF"));

        foreach ($lines as $line) {
            $cells = str_getcsv($line);
            if (($cells[0] ?? '') === $reference) {
                return [$header, $cells];
            }
        }

        self::fail("the export has no row for {$reference}");
    }

    private function donationService(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }
}
