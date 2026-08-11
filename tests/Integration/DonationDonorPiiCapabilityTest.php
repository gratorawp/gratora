<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use WP_REST_Request;

/**
 * dono_view_donations reads the donation record. Who gave, and how to reach
 * them, is the donor record, and that is dono_view_donors: paging the admin
 * donations list would otherwise hand a donations-only role the whole donor
 * email list, which the CSV export already refuses to do.
 */
final class DonationDonorPiiCapabilityTest extends IntegrationTestCase
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

    private function seedDonation(): string
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'sarah.mueller@example.de',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Müller', 'country' => 'DE'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }

    public function test_the_detail_route_withholds_contact_details_without_view_donors(): void
    {
        $reference = $this->seedDonation();
        $this->actAs(['dono_view_donations']);

        $res = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$reference}"));
        $this->assertSame(200, $res->get_status());

        $donor = ((array) $res->get_data())['donor'];
        $this->assertNotNull($donor, 'the donation still says whose it is');
        $this->assertSame('Sarah Müller', $donor['name'], 'the display name stays, so the screen works');
        $this->assertNull($donor['email'], 'email is the donor record');
        $this->assertNull($donor['phone'], 'phone is the donor record');
        $this->assertNull($donor['address'], 'address is the donor record');
    }

    public function test_the_list_route_withholds_donor_email_without_view_donors(): void
    {
        $this->seedDonation();
        $this->actAs(['dono_view_donations']);

        $req = new WP_REST_Request('GET', '/dono/v1/admin/donations');
        $req->set_query_params(['page' => 1, 'per_page' => 25]);
        $rows = (array) rest_do_request($req)->get_data();

        $this->assertNotSame([], $rows, 'the donations list is still readable');
        $emails = array_filter(array_map(
            static fn (array $r): ?string => $r['donor']['email'] ?? null,
            $rows,
        ));
        $this->assertSame([], $emails, 'no row hands back a donor email');
    }

    public function test_view_donors_still_reads_the_contact_details(): void
    {
        $reference = $this->seedDonation();
        $this->actAs(['dono_view_donations', 'dono_view_donors']);

        $res   = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$reference}"));
        $donor = ((array) $res->get_data())['donor'];

        $this->assertSame('sarah.mueller@example.de', $donor['email']);
    }
}
