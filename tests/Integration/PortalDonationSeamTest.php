<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Admin\ExtensionAssets;
use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * A donation carries records an add-on owns, and the donor is entitled to see
 * them in their portal. These pin the two halves of that: the payload filter
 * that lets an add-on add its own section to the donation, and the browser
 * panel registry the portal renders it through.
 */
final class PortalDonationSeamTest extends IntegrationTestCase
{
    public function test_an_add_on_can_add_its_own_section_to_a_donation_payload(): void
    {
        $reference = $this->paidDonation();

        add_filter('dono.portal.donation', static function (array $payload, Donation $donation): array {
            $payload['keepsake'] = ['reference' => (string) $donation->reference];

            return $payload;
        }, 10, 2);

        $payload = $this->showDonation($reference);

        $this->assertSame(['reference' => $reference], $payload['keepsake'] ?? null);
        $this->assertSame($reference, $payload['reference'] ?? null, 'core fields are untouched');
    }

    public function test_the_filter_receives_the_donation_the_payload_describes(): void
    {
        $reference = $this->paidDonation();

        $seen = null;
        add_filter('dono.portal.donation', static function (array $payload, Donation $donation) use (&$seen): array {
            $seen = $donation;

            return $payload;
        }, 10, 2);

        $this->showDonation($reference);

        $this->assertInstanceOf(Donation::class, $seen);
        $this->assertSame($reference, (string) $seen->reference);
    }

    /** The registry has to exist before an add-on bundle calls register on it. */
    public function test_the_extension_registry_offers_a_panel_surface(): void
    {
        global $wp_scripts;

        ExtensionAssets::enqueue('portal');

        $inline = implode('', (array) ($wp_scripts->registered[ExtensionAssets::HANDLE]->extra['after'] ?? []));
        $this->assertStringContainsString('window.dono.panels', $inline);
    }

    /** @return array<string,mixed> */
    private function showDonation(string $reference): array
    {
        $donation = Donation::query()->find('reference', $reference);

        $sid = $this->portalSession((int) $donation->donor_id, bin2hex(random_bytes(8)));
        $_COOKIE['dono_donor_session'] = $sid;

        try {
            $res = rest_do_request(new WP_REST_Request('GET', "/dono/v1/portal/donations/{$reference}"));
            $this->assertSame(200, $res->get_status());

            return (array) $res->get_data();
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }
    }

    private function paidDonation(): string
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'portal-seam@example.test',
            'amount_cents' => 4200,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Nell', 'last_name' => 'Porter'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }
}
