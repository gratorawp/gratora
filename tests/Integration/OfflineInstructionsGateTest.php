<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Offline has no API behind it: what the org writes in settings is the entire
 * payment rail. Offered with nothing written, the donor commits, is told
 * instructions are on the way, and receives an email with a blank space where
 * the account number should be. There is no way forward from there, so the
 * method must not be on the form at all.
 */
final class OfflineInstructionsGateTest extends IntegrationTestCase
{
    private function gateways(): GatewayManager
    {
        return Plugin::instance()->container->get(GatewayManager::class);
    }

    /** @param array<string,mixed> $offline */
    private function configureOffline(array $offline): void
    {
        update_option('gratora_gateway_config', ['offline' => $offline]);
    }

    /** @param array<string,mixed> $body */
    private function postDonation(array $body): WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode($body));
        return rest_do_request($req);
    }

    private function donationCount(): int
    {
        return (int) self::$wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::$prefix . 'gratora_donations'
        );
    }

    public function test_offline_is_not_switched_on_when_the_org_wrote_no_instructions(): void
    {
        $this->configureOffline(['enabled' => true, 'instructions' => '', 'bank_details' => '']);

        $this->assertFalse($this->gateways()->isOn('offline'));
    }

    public function test_the_form_does_not_list_offline_when_the_org_wrote_no_instructions(): void
    {
        $this->configureOffline(['enabled' => true, 'instructions' => '', 'bank_details' => '']);

        $this->assertNotContains('offline', $this->gateways()->optionsFor([], null, 'USD', 'one_time'));
    }

    public function test_the_form_advertises_no_offline_metadata_when_the_org_wrote_no_instructions(): void
    {
        $this->configureOffline(['enabled' => true, 'instructions' => '', 'bank_details' => '']);

        $this->assertSame(
            [],
            array_column($this->gateways()->optionsMetaFor(['offline']), 'id'),
            'the form still advertised a method with nothing to pay to'
        );
    }

    public function test_whitespace_is_not_instructions(): void
    {
        $this->configureOffline(['enabled' => true, 'instructions' => "  \n\t ", 'bank_details' => '']);

        $this->assertFalse($this->gateways()->isOn('offline'));
    }

    public function test_a_donation_naming_offline_is_refused_instead_of_left_unpayable(): void
    {
        $this->configureOffline(['enabled' => true, 'instructions' => '', 'bank_details' => '']);

        $res = $this->postDonation([
            'email'        => 'blank@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ]);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('gratora_gateway_not_allowed', $res->get_data()['code'] ?? null);
        $this->assertSame(0, $this->donationCount(), 'a pending row nobody can ever pay was still written');
    }

    public function test_bank_details_alone_are_enough_to_pay_by(): void
    {
        $this->configureOffline([
            'enabled'      => true,
            'instructions' => '',
            'bank_details' => "IBAN: DE89 3704 0044 0532 0130 00\nReference: {reference}",
        ]);

        $this->assertTrue($this->gateways()->isOn('offline'));

        $res = $this->postDonation([
            'email'        => 'iban@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ]);

        $this->assertSame(201, $res->get_status(), (string) json_encode($res->get_data()));
    }

    public function test_instructions_make_offline_payable_again(): void
    {
        $this->configureOffline(['enabled' => true, 'instructions' => 'Post a cheque payable to Us.']);

        $this->assertTrue($this->gateways()->isOn('offline'));
        $this->assertContains('offline', $this->gateways()->optionsFor([], null, 'USD', 'one_time'));

        $res = $this->postDonation([
            'email'        => 'cheque@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ]);

        $this->assertSame(201, $res->get_status(), (string) json_encode($res->get_data()));
    }

    public function test_switching_offline_off_still_takes_it_off_the_form(): void
    {
        $this->configureOffline(['enabled' => false, 'instructions' => 'Post a cheque payable to Us.']);

        $this->assertFalse($this->gateways()->isOn('offline'));
        $this->assertNotContains('offline', $this->gateways()->optionsFor([], null, 'USD', 'one_time'));
    }
}
