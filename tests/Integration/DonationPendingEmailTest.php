<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Verifies the `dono.donation.pending` event fires the `donation_pending`
 * email template once and only when the gateway leaves the donation in
 * pending status (Stripe `requires_action`, SEPA, etc.).
 */
final class DonationPendingEmailTest extends IntegrationTestCase
{
    public function test_mark_pending_sends_donation_pending_template(): void
    {
        $mails    = $this->captureMails();
        $donation = $this->driveOfflineDonation();

        $service = Plugin::instance()->container->get(DonationService::class);
        $service->markPending($donation, 'requires_action');

        $pending = $this->mailsBySubject($mails, 'processing');

        $this->assertCount(1, $pending, 'donation_pending template sent once');
        $this->assertSame($donation->reference, $this->referenceFromBody($pending[0]['message']));
        $this->assertStringContainsString('Sarah', $pending[0]['message'], 'donor_first_name interpolated');
        $this->assertStringContainsString('processing', strtolower($pending[0]['subject']));
        $this->assertEmpty($pending[0]['attachments'], 'pending email is plain text, no PDF');
    }

    public function test_offline_donation_emails_payment_instructions(): void
    {
        $mails = $this->captureMails();
        $this->driveOfflineDonation(); // fires dono.donation.intent_created → offline_instructions

        $instructions = $this->mailsBySubject($mails, 'instructions');
        $this->assertCount(1, $instructions, 'offline donors are emailed how to pay');
    }

    public function test_offline_instructions_email_interpolates_every_token(): void
    {
        // Admins may reference donor-specific placeholders inside the offline
        // instructions / bank details; the email must fill them and never leak
        // a literal {token}.
        update_option('dono_gateway_config', [
            'offline' => [
                'enabled'      => true,
                'instructions' => 'Hi {donor_name}, please send {amount} quoting {reference}.',
                'bank_details' => "IBAN: DE89 3704 0044 0532 0130 00\nReference: {reference}",
            ],
        ]);

        $mails    = $this->captureMails();
        $donation = $this->driveOfflineDonation();

        $instructions = $this->mailsBySubject($mails, 'instructions');
        $this->assertCount(1, $instructions, 'offline donors are emailed how to pay');
        $body = (string) $instructions[0]['message'];

        foreach (['{campaign_title}', '{instructions}', '{bank_details}', '{amount}', '{reference}', '{donor_name}'] as $literal) {
            $this->assertStringNotContainsString($literal, $body, "no literal {$literal} leaks into the donor email");
        }

        $this->assertStringContainsString('Sarah', $body, 'donor name reaches the instructions text');
        $this->assertStringContainsString($donation->reference, $body, 'reference fills both instructions and bank details');
        $this->assertStringContainsString('DE89 3704 0044 0532 0130 00', $body, 'configured bank details are surfaced');

        delete_option('dono_gateway_config');
    }

    public function test_mark_pending_is_noop_when_donation_already_paid(): void
    {
        $mails    = $this->captureMails();
        $donation = $this->driveOfflineDonation();

        // Confirm first → status flips to paid.
        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$donation->reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);
        $this->runPendingAsyncJobs();

        // Re-read to reflect the post-confirm state on disk.
        $repo  = Plugin::instance()->container->get(\Dono\Donations\DonationRepository::class);
        $fresh = $repo->findByReference($donation->reference);

        $service = Plugin::instance()->container->get(DonationService::class);
        $service->markPending($fresh, 'requires_action');

        $pending = $this->mailsBySubject($mails, 'processing');
        $this->assertCount(0, $pending, 'paid donations never get a pending email');
    }

    public function test_template_disabled_skips_send(): void
    {
        update_option('dono_email_settings', [
            'templates' => [
                'donation_pending' => ['enabled' => false],
            ],
        ]);

        $mails    = $this->captureMails();
        $donation = $this->driveOfflineDonation();

        $service = Plugin::instance()->container->get(DonationService::class);
        $service->markPending($donation, 'requires_action');

        $pending = $this->mailsBySubject($mails, 'processing');
        $this->assertCount(0, $pending, 'Disabled template suppresses send');

        delete_option('dono_email_settings');
    }

    private function driveOfflineDonation(): \Dono\Donations\Donation
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body((string) wp_json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Müller'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $repo = Plugin::instance()->container->get(\Dono\Donations\DonationRepository::class);
        return $repo->findByReference($reference);
    }

    /**
     * @return list<array{to:?string,subject:?string,message:?string,headers:?string,attachments:array<int,string>}>
     */
    private function mailsBySubject(\ArrayObject $mails, string $needle): array
    {
        $hits = [];
        foreach ($mails as $m) {
            if (stripos((string) ($m['subject'] ?? ''), $needle) !== false) {
                $hits[] = $m;
            }
        }
        return $hits;
    }

    private function referenceFromBody(?string $body): string
    {
        if ($body === null) return '';
        if (preg_match('/Reference:\s*(\S+)/', $body, $m)) {
            return $m[1];
        }
        return '';
    }
}
