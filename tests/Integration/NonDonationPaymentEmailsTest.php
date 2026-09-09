<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use ArrayObject;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Foundation\Plugin;

/**
 * An add-on can move money through the donation rails that is not a donation:
 * a ticket order stamps kind 'order' on the intent. Core still owes that payer
 * every notice about their money, and telling them their purchase was a
 * donation is telling them the wrong thing about it.
 */
final class NonDonationPaymentEmailsTest extends IntegrationTestCase
{
    public function test_a_refunded_order_is_not_called_a_donation(): void
    {
        $donation = $this->paidDonationOfKind('order');

        $mails = $this->captureMails();
        $this->service()->refund($donation, 5000, 'sold out');

        $body = $this->onlyMailMatching($mails, 'refund');
        $this->assertStringContainsString('refunded your payment', $body);
        $this->assertStringNotContainsString('your donation', $body);
    }

    public function test_a_refunded_donation_is_still_called_a_donation(): void
    {
        $donation = $this->paidDonationOfKind('donation');

        $mails = $this->captureMails();
        $this->service()->refund($donation, 5000, 'donor requested');

        $body = $this->onlyMailMatching($mails, 'refund');
        $this->assertStringContainsString('refunded your donation', $body);
    }

    public function test_a_pending_order_is_not_called_a_donation(): void
    {
        $donation = $this->pendingDonationOfKind('order');

        $mails = $this->captureMails();
        $this->service()->markPending($donation, 'requires_action');

        $body = $this->onlyMailMatching($mails, 'processing');
        $this->assertStringContainsString('received your payment', $body);
        $this->assertStringNotContainsString('your donation', $body);
    }

    public function test_offline_instructions_for_an_order_are_not_called_a_donation(): void
    {
        $mails    = $this->captureMails();
        $donation = $this->pendingDonationOfKind('order');

        $body = $this->onlyMailMatching($mails, 'instructions');
        $this->assertStringNotContainsString('with a donation of', $body);
        $this->assertStringContainsString($donation->reference, $body);
    }

    public function test_offline_instructions_for_a_donation_still_thank_the_donor_for_one(): void
    {
        $mails = $this->captureMails();
        $this->pendingDonationOfKind('donation');

        $body = $this->onlyMailMatching($mails, 'instructions');
        $this->assertStringContainsString('with a donation of', $body);
    }

    public function test_an_addon_can_answer_in_its_own_words(): void
    {
        update_option('gratora_email_settings', [
            'templates' => [
                'ticket_order_refunded' => [
                    'enabled' => true,
                    'subject' => 'Your tickets have been refunded',
                    'body'    => 'We refunded {amount} for your tickets.',
                ],
            ],
        ]);
        add_filter('gratora.email.donation_template', static function (string $template, string $base, Donation $donation): string {
            return $base === 'donation_refunded' && (string) $donation->kind === 'order'
                ? 'ticket_order_refunded'
                : $template;
        }, 10, 3);

        $donation = $this->paidDonationOfKind('order');

        $mails = $this->captureMails();
        $this->service()->refund($donation, 5000, 'sold out');

        $body = $this->onlyMailMatching($mails, 'tickets have been refunded');
        $this->assertStringContainsString('for your tickets', $body);

        delete_option('gratora_email_settings');
    }

    private function paidDonationOfKind(string $kind): Donation
    {
        $donation = $this->pendingDonationOfKind($kind);
        $this->service()->confirm($donation, ['gateway_payment_id' => 'off_' . uniqid()]);
        $this->runPendingAsyncJobs();

        return $this->donations()->findByReference($donation->reference);
    }

    /** The intent an add-on's form-type handler hands back, kind and all. */
    private function pendingDonationOfKind(string $kind): Donation
    {
        return $this->service()->createPending(new DonationIntent(
            email: 'buyer@example.com',
            amount_cents: 5000,
            currency: 'USD',
            gateway: 'offline',
            profile: ['first_name' => 'Sam', 'last_name' => 'Reed', 'country' => 'US'],
            kind: $kind,
            is_test: false,
        ))['donation'];
    }

    private function onlyMailMatching(ArrayObject $mails, string $needle): string
    {
        $hits = [];
        foreach ($mails as $mail) {
            $haystack = (string) ($mail['subject'] ?? '') . ' ' . (string) ($mail['message'] ?? '');
            if (stripos($haystack, $needle) !== false) {
                $hits[] = (string) $mail['message'];
            }
        }
        $this->assertCount(1, $hits, "exactly one email matching '{$needle}'");

        return $hits[0];
    }

    private function service(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }
}
