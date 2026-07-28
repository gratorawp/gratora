<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use ArrayObject;
use WP_REST_Request;

/**
 * The tribute block asks a donor for someone to notify, and the address was
 * encrypted and stored and then read by nothing at all: the family never heard
 * a word. These cover the send, and the three cases where staying silent is the
 * correct behaviour.
 */
final class TributeNotificationEmailTest extends IntegrationTestCase
{
    private const HONOREE_CONTACT = 'family@example.test';

    public function test_the_honoree_contact_is_told_a_donation_was_made(): void
    {
        $mails = $this->captureMails();
        $this->completeTributeDonation();

        $sent = $this->mailsTo($mails, self::HONOREE_CONTACT);
        $this->assertCount(1, $sent, 'the person the donor named is emailed exactly once');

        $body = (string) $sent[0]['message'];
        $this->assertStringContainsString('Ada Lovelace', $body, 'the honoree is named');
        $this->assertStringContainsString('in memory of', $body, 'the tribute type reads as a phrase, not the raw id');
        $this->assertStringContainsString('Grace Hopper', $body, 'the donor is named');
        $this->assertStringContainsString('She taught me to code', $body, 'the donor message is passed on');
        $this->assertStringContainsString('Ada Lovelace', (string) $sent[0]['subject']);
    }

    public function test_an_anonymous_donor_is_not_named_to_the_family(): void
    {
        $mails = $this->captureMails();
        $this->completeTributeDonation(['is_anonymous' => true]);

        $sent = $this->mailsTo($mails, self::HONOREE_CONTACT);
        $this->assertCount(1, $sent, 'anonymity hides the name, it does not cancel the notification');

        $body = (string) $sent[0]['message'];
        $this->assertStringNotContainsString('Grace Hopper', $body, 'anonymous means anonymous to the family too');
        $this->assertStringContainsString('A donor', $body);
    }

    /**
     * A staging run must not tell a real family that someone died. The receipt
     * can carry a test banner because it goes back to whoever ran the test; this
     * one goes to a third party who never asked to hear from us.
     */
    public function test_a_test_mode_donation_notifies_nobody(): void
    {
        update_option('dono_gateway_config', ['test_mode' => true]);

        $mails = $this->captureMails();
        $this->completeTributeDonation();

        $this->assertCount(0, $this->mailsTo($mails, self::HONOREE_CONTACT));

        delete_option('dono_gateway_config');
    }

    public function test_a_disabled_template_sends_nothing(): void
    {
        update_option('dono_email_settings', ['templates' => ['tribute_notification' => ['enabled' => false]]]);

        $mails = $this->captureMails();
        $this->completeTributeDonation();

        $this->assertCount(0, $this->mailsTo($mails, self::HONOREE_CONTACT));

        delete_option('dono_email_settings');
    }

    /** A tribute without a notify address is a private dedication. */
    public function test_a_tribute_with_no_notify_address_emails_nobody_new(): void
    {
        $mails = $this->captureMails();
        $before = count($mails);
        $this->completeTributeDonation(['notify_email' => null]);

        foreach ($mails as $i => $m) {
            if ($i < $before) continue;
            $this->assertNotSame(
                self::HONOREE_CONTACT,
                (string) ($m['to'] ?? ''),
                'nothing addressed to the honoree contact when none was given'
            );
        }
    }

    /**
     * @param array{is_anonymous?:bool,notify_email?:?string} $opts
     */
    private function completeTributeDonation(array $opts = []): void
    {
        $tribute = [
            'type'    => 'memorial',
            'name'    => 'Ada Lovelace',
            'message' => 'She taught me to code.',
        ];
        $notify = array_key_exists('notify_email', $opts) ? $opts['notify_email'] : self::HONOREE_CONTACT;
        if ($notify !== null) $tribute['notify_email'] = $notify;

        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body((string) wp_json_encode([
            'email'        => 'grace@example.test',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'is_anonymous' => (bool) ($opts['is_anonymous'] ?? false),
            'profile'      => ['first_name' => 'Grace', 'last_name' => 'Hopper'],
            'tribute'      => $tribute,
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);
        $this->runPendingAsyncJobs();
    }

    /**
     * @return list<array{to:?string,subject:?string,message:?string}>
     */
    private function mailsTo(ArrayObject $mails, string $address): array
    {
        $hits = [];
        foreach ($mails as $m) {
            if ((string) ($m['to'] ?? '') === $address) $hits[] = $m;
        }
        return $hits;
    }
}
