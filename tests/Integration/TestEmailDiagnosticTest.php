<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * What the admin is told when a test email will not send.
 *
 * wp_mail catches the PHPMailer exception itself and returns a bare false, so
 * the reason only ever reaches the wp_mail_failed action. A diagnostic that
 * reports "it failed" and nothing else sends the admin to their host's logs for
 * something the mail server already said out loud.
 */
final class TestEmailDiagnosticTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function send(): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/email/test-send');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['to' => 'diagnostic@dono.test']));

        return rest_do_request($req);
    }

    public function test_the_mail_servers_own_reason_is_reported(): void
    {
        add_filter('pre_wp_mail', static function () {
            do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', 'SMTP connect() failed.'));
            return false;
        });

        $res = $this->send();
        $data = (array) $res->get_data();

        $this->assertSame(500, $res->get_status());
        $this->assertStringContainsString(
            'SMTP connect() failed.',
            (string) ($data['message'] ?? ''),
            'the admin is told what the server said'
        );
    }

    public function test_a_silent_failure_names_the_likely_cause(): void
    {
        // No wp_mail_failed fired: the usual shape of a host with no transport
        // at all, where PHP mail() returns false without explanation.
        add_filter('pre_wp_mail', static fn () => false);

        $data = (array) $this->send()->get_data();

        $this->assertStringContainsString('no mail transport', (string) ($data['message'] ?? ''));
    }

    public function test_the_result_names_the_transport_that_carried_it(): void
    {
        // "Sent" is the answer that misled: PHP mail() accepts a message that
        // any SPF-checking mailbox will reject later. The operator has to be
        // able to see, before a donor complains, that nothing authenticated it.
        $res = $this->send();

        $this->assertSame(200, $res->get_status());
        $data = (array) $res->get_data();

        $this->assertSame('mail', $data['transport'], 'a stock site sends over PHP mail()');
        $this->assertFalse($data['authenticated']);
    }

    public function test_an_unknown_transport_is_not_called_unauthenticated(): void
    {
        // A mail plugin that short-circuits wp_mail never builds PHPMailer, so
        // there is nothing to inspect. Saying "unauthenticated" there would be
        // a false alarm on a correctly configured site.
        add_filter('pre_wp_mail', static fn () => true);

        $data = (array) $this->send()->get_data();

        $this->assertSame('', $data['transport']);
        $this->assertNull($data['authenticated'], 'unknown is its own answer');
    }

    public function test_a_working_transport_reports_success(): void
    {
        add_filter('pre_wp_mail', static fn () => true);

        $res = $this->send();

        $this->assertSame(200, $res->get_status());
        $this->assertTrue(((array) $res->get_data())['ok']);
    }
}
