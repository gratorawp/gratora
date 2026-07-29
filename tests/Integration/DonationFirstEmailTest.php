<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * The first-donation welcome: when a donor's first donation completes, the
 * donation_first template is emailed once. A second donation from the same
 * donor never re-sends it, and disabling the template suppresses it.
 */
final class DonationFirstEmailTest extends IntegrationTestCase
{
    public function test_first_completed_donation_sends_the_welcome_once(): void
    {
        $mails = $this->captureMails();
        $this->completeOfflineDonation('sarah@example.com', 'Sarah');

        $welcome = $this->mailsBySubject($mails, 'first');
        $this->assertCount(1, $welcome, 'the first donation sends the welcome once');
        $this->assertStringContainsString('Sarah', (string) $welcome[0]['message'], 'donor_first_name interpolated');
    }

    public function test_second_donation_does_not_resend_the_welcome(): void
    {
        $mails = $this->captureMails();
        $this->completeOfflineDonation('repeat@example.com', 'Rita');
        $this->completeOfflineDonation('repeat@example.com', 'Rita');

        $welcome = $this->mailsBySubject($mails, 'first');
        $this->assertCount(1, $welcome, 'only the first donation triggers the welcome');
    }

    /**
     * A cheque an admin typed in is not the donor saying hello, so it sends
     * nothing. What it must not do is use up the welcome: the aggregate crosses
     * 0 -> 1 on the cheque, and if that crossing is what the email watches, the
     * donor's own first donation moves it 1 -> 2 and they are never welcomed at
     * all. M5 from the manual-donations review.
     */
    public function test_a_donor_first_recorded_by_hand_is_welcomed_when_they_donate_themselves(): void
    {
        $mails = $this->captureMails();

        $this->recordDonationByHand('margit@example.com', 'Margit');
        $this->assertCount(0, $this->mailsBySubject($mails, 'first'), 'a hand-recorded cheque welcomed nobody');

        $this->completeOfflineDonation('margit@example.com', 'Margit');

        $welcome = $this->mailsBySubject($mails, 'first');
        $this->assertCount(1, $welcome, 'the donor was never welcomed for the donation they made themselves');
        $this->assertStringContainsString('Margit', (string) $welcome[0]['message']);
    }

    /** And still only once: the cheque before it does not earn a second. */
    public function test_the_welcome_is_not_repeated_after_a_hand_recorded_start(): void
    {
        $mails = $this->captureMails();

        $this->recordDonationByHand('yusuf@example.com', 'Yusuf');
        $this->completeOfflineDonation('yusuf@example.com', 'Yusuf');
        $this->completeOfflineDonation('yusuf@example.com', 'Yusuf');

        $this->assertCount(1, $this->mailsBySubject($mails, 'first'));
    }

    public function test_disabled_template_skips_the_welcome(): void
    {
        update_option('dono_email_settings', ['templates' => ['donation_first' => ['enabled' => false]]]);

        $mails = $this->captureMails();
        $this->completeOfflineDonation('optout@example.com', 'Omar');

        $this->assertCount(0, $this->mailsBySubject($mails, 'first'), 'a disabled welcome never sends');

        delete_option('dono_email_settings');
    }

    private function recordDonationByHand(string $email, string $firstName): void
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'          => $email,
            'first_name'     => $firstName,
            'last_name'      => 'Test',
            'amount_cents'   => 12500,
            'currency'       => 'USD',
            'payment_method' => 'cheque',
            'received_at'    => '2026-06-14',
        ]));
        rest_do_request($req);
        $this->runPendingAsyncJobs();
    }

    private function completeOfflineDonation(string $email, string $firstName): void
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => $firstName, 'last_name' => 'Test'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);
        $this->runPendingAsyncJobs();
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
}
