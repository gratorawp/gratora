<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Donations\DonationRepository;
use Dono\Receipts\ReceiptIssuer;
use WP_REST_Request;

/**
 * The receipt PDF written for wp_mail's attachment API does not outlive the send.
 *
 * wp_mail takes file paths rather than bytes, so the PDF has to exist on disk
 * for a moment. It carries the donor's name, their address where the template
 * includes one, and what they gave, in a directory shared with every other
 * process on the host.
 *
 * The send is also wrapped in finally, so a mailer that throws cannot leave the
 * file behind either. That half is not asserted here: nothing this harness can
 * do to wp_mail propagates far enough to exercise it, and a test that cannot be
 * made to fail is not evidence.
 */
final class ReceiptTempFileTest extends IntegrationTestCase
{
    /** @return string[] receipt PDFs currently sitting in the system temp dir */
    private function strays(): array
    {
        return glob(rtrim(get_temp_dir(), '/\\') . '/dono-receipt-*.pdf') ?: [];
    }

    private function paidDonation(): \Dono\Donations\Donation
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'temp-file@example.test',
            'amount_cents' => 4200,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Temp', 'last_name' => 'File'],
        ]));
        $reference = (string) (rest_do_request($create)->get_data()['reference'] ?? '');

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    public function test_a_sent_receipt_leaves_nothing_on_disk(): void
    {
        $before = $this->strays();

        $issuer = Plugin::instance()->container->get(ReceiptIssuer::class);
        $issuer->onDonationCompleted($this->paidDonation());
        $this->runPendingAsyncJobs();

        $this->assertSame($before, $this->strays(), 'a receipt PDF was left in the temp directory');
    }
}
