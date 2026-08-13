<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Core\Commands\CoreCommandProvider;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Foundation\Plugin;
use Dono\Receipts\ReceiptIssuer;
use Dono\Receipts\ReceiptRepository;
use Dono\Settings\SettingsService;
use WP_REST_Request;

/**
 * A receipt PDF is a donor record in another wrapper: it carries the donor's
 * postal address wherever the org turned that on, and their email wherever the
 * merge tag sits in the template. dono_view_donors is what gates those fields
 * on the donations list, the detail payload and the CSV export, so the download
 * that reproduces them has to ask for the same thing. Otherwise a bookkeeper
 * role deliberately given donations but not donors walks the receipt ids and
 * reads the address book one PDF at a time.
 */
final class ReceiptPdfCapabilityTest extends IntegrationTestCase
{
    /** A fresh subscriber (no manage_options) holding exactly $caps. */
    private function actAs(array $caps): void
    {
        $uid  = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $uid);
        foreach ($caps as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($uid);
    }

    private function paidDonation(): Donation
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'receipt.pdf@example.test',
            'amount_cents' => 7500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'frequency'    => 'one_time',
            'profile'      => [
                'first_name' => 'Receipt',
                'last_name'  => 'Reader',
                'address'    => [
                    'line1'   => '14 Hidden Row',
                    'city'    => 'Bristol',
                    'postal'  => 'BS1 4AA',
                    'country' => 'GB',
                ],
            ],
        ]));
        $reference = (string) (rest_do_request($create)->get_data()['reference'] ?? '');

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    /** The id the donations screen hands the Download PDF button. */
    private function issuedReceiptId(): int
    {
        // The address on the PDF is what makes this a donor record rather than
        // a donation record, so the test issues it the way an org that prints
        // addresses would.
        Plugin::instance()->container->get(SettingsService::class)
            ->update('receipts', ['show_donor_address' => true]);

        $donation = $this->paidDonation();
        Plugin::instance()->container->get(ReceiptIssuer::class)->onDonationCompleted($donation);
        $this->runPendingAsyncJobs();

        $receipts = Plugin::instance()->container->get(ReceiptRepository::class)
            ->forDonation((int) $donation->id);

        $this->assertNotSame([], $receipts, 'the donation issued a receipt to download');

        return (int) $receipts[0]->id;
    }

    private function downloadStatus(int $receiptId): int
    {
        return rest_do_request(
            new WP_REST_Request('GET', "/dono/v1/admin/receipts/{$receiptId}/pdf")
        )->get_status();
    }

    public function test_the_receipt_pdf_is_refused_without_view_donors(): void
    {
        $receiptId = $this->issuedReceiptId();
        $this->actAs(['dono_view_donations']);

        $this->assertContains(
            $this->downloadStatus($receiptId),
            [401, 403],
            'a donations-only role cannot download a receipt carrying donor contact details'
        );
    }

    public function test_view_donors_still_downloads_the_receipt_pdf(): void
    {
        $receiptId = $this->issuedReceiptId();
        $this->actAs(['dono_view_donations', 'dono_view_donors']);

        $this->assertSame(
            200,
            $this->downloadStatus($receiptId),
            'the role the receipt was meant for still gets the PDF'
        );
    }

    /**
     * Reading a donation is not reading the donor, so the gate has to hold both
     * ways round: the receipt download must not become the back door in either
     * direction.
     */
    public function test_view_donors_alone_does_not_reach_the_receipt_pdf(): void
    {
        $receiptId = $this->issuedReceiptId();
        $this->actAs(['dono_view_donors']);

        $this->assertContains(
            $this->downloadStatus($receiptId),
            [401, 403],
            'the download still needs the donations capability it always did'
        );
    }

    /**
     * The same PDF is reachable as a command, which the assistant dispatches.
     * A second door into the same bytes has to ask for the same thing.
     */
    public function test_the_render_pdf_command_refuses_without_view_donors(): void
    {
        $receiptId = $this->issuedReceiptId();
        $this->actAs(['dono_resend_receipt']);

        $container = Plugin::instance()->container;
        $registry  = new CommandRegistry($container->get(EventRecorder::class));
        (new CoreCommandProvider())->register($registry, $container);

        $result = $registry->dispatch(
            'receipt.render_pdf',
            ['receipt_id' => $receiptId],
            new CommandContext(get_current_user_id(), 'rest', 'req-' . uniqid())
        );

        $this->assertFalse($result->ok, 'the command hands back a receipt full of donor details');
        $this->assertStringContainsString('dono_view_donors', (string) $result->error);
    }
}
