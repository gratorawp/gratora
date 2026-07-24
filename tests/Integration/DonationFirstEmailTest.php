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

    public function test_disabled_template_skips_the_welcome(): void
    {
        update_option('dono_email_settings', ['templates' => ['donation_first' => ['enabled' => false]]]);

        $mails = $this->captureMails();
        $this->completeOfflineDonation('optout@example.com', 'Omar');

        $this->assertCount(0, $this->mailsBySubject($mails, 'first'), 'a disabled welcome never sends');

        delete_option('dono_email_settings');
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
