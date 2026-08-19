<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * What the portal hands the browser is the whole account a donor gets of their
 * own giving. Two things it has to carry: the currency an amount is in, because
 * minor units are not comparable across currencies, and what came back on a
 * refund, because the lifetime total the same screen shows is net of one.
 */
final class PortalDonationPayloadTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'EUR', 'GBP', 'JPY'],
        ]);
        update_option('dono_fx_rates', [
            'base'       => 'USD',
            'date'       => gmdate('Y-m-d'),
            'fetched_at' => gmdate('c'),
            'rates'      => ['USD' => 1.0, 'EUR' => 1.0, 'GBP' => 1.0, 'JPY' => 150.0],
        ], false);
    }

    public function test_a_give_again_link_says_which_currency_its_amount_is_in(): void
    {
        $campaignId = $this->createCampaign();
        $reference  = $this->paidDonation(500000, 'JPY', $campaignId);

        $payload = $this->showDonation($reference);

        $url = (string) ($payload['give_again_url'] ?? '');
        $this->assertNotSame('', $url, 'the campaign has a page, so the link is offered');

        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('500000', (string) ($query['dono_amount'] ?? ''));
        $this->assertSame(
            'JPY',
            (string) ($query['dono_currency'] ?? ''),
            'without this the form reads 500000 as its own currency: 5,000 yen becomes 5,000 dollars'
        );
    }

    public function test_a_partly_refunded_donation_says_what_came_back(): void
    {
        $reference = $this->paidDonation(5000, 'USD');
        $this->refund($reference, 1000);

        $payload = $this->showDonation($reference);

        $this->assertSame(5000, (int) $payload['amount_cents'], 'the donor gave this');
        $this->assertArrayHasKey('refunded_cents', $payload, 'nothing else on the screen can explain the difference');
        $this->assertSame(1000, (int) $payload['refunded_cents'], 'and this much came back');
    }

    public function test_the_donations_list_names_the_refund_too(): void
    {
        $reference = $this->paidDonation(5000, 'USD');
        $this->refund($reference, 1000);

        $row = $this->listDonations($reference);

        $this->assertSame(5000, (int) $row['amount_cents']);
        $this->assertArrayHasKey('refunded_cents', $row);
        $this->assertSame(
            1000,
            (int) $row['refunded_cents'],
            'the row sits under a lifetime total that is net, and nothing else explains the gap'
        );
    }

    public function test_a_donation_nobody_refunded_reports_nothing_refunded(): void
    {
        $reference = $this->paidDonation(5000, 'USD');

        $show = $this->showDonation($reference);
        $row  = $this->listDonations($reference);

        $this->assertArrayHasKey('refunded_cents', $show);
        $this->assertArrayHasKey('refunded_cents', $row);
        $this->assertSame(0, (int) $show['refunded_cents']);
        $this->assertSame(0, (int) $row['refunded_cents']);
    }

    private function createCampaign(): int
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Give again', 'status' => 'published']));

        return (int) rest_do_request($req)->get_data()['id'];
    }

    private function paidDonation(int $amountCents, string $currency, ?int $campaignId = null): string
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode(array_filter([
            'email'        => 'portal-payload@example.test',
            'amount_cents' => $amountCents,
            'currency'     => $currency,
            'gateway'      => 'offline',
            'campaign_id'  => $campaignId,
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
        ])));
        $created = (array) rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $created, (string) wp_json_encode($created));
        $reference = (string) $created['reference'];

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }

    private function refund(string $reference, int $amountCents): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $was   = get_current_user_id();
        wp_set_current_user($admin);

        try {
            $req = new WP_REST_Request('POST', "/dono/v1/admin/donations/{$reference}/refund");
            $req->set_header('content-type', 'application/json');
            $req->set_body((string) wp_json_encode(['amount_cents' => $amountCents]));
            $res = rest_do_request($req);
            $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        } finally {
            wp_set_current_user($was);
        }
    }

    /** @return array<string,mixed> */
    private function showDonation(string $reference): array
    {
        return (array) $this->asDonor($reference, "/dono/v1/portal/donations/{$reference}");
    }

    /** @return array<string,mixed> */
    private function listDonations(string $reference): array
    {
        $rows = (array) $this->asDonor($reference, '/dono/v1/portal/donations');
        foreach ($rows as $row) {
            if (($row['reference'] ?? '') === $reference) {
                return (array) $row;
            }
        }
        $this->fail("Donation {$reference} is missing from the portal list.");
    }

    private function asDonor(string $reference, string $route): mixed
    {
        $donation = Donation::query()->find('reference', $reference);

        $sid = $this->portalSession((int) $donation->donor_id, bin2hex(random_bytes(8)));
        $_COOKIE['dono_donor_session'] = $sid;

        try {
            $res = rest_do_request(new WP_REST_Request('GET', $route));
            $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

            return $res->get_data();
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }
    }
}
