<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * The subscriptions list reads the same donor record the donations list already
 * withholds from a donations-only role. Paging it one plan at a time is the
 * donor list by another route.
 */
final class RecurringDonorPiiCapabilityTest extends IntegrationTestCase
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

    private function seedPlanForDonor(): int
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'noor.haddad@example.org',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Noor', 'last_name' => 'Haddad'],
        ]));
        rest_do_request($create);

        $donor = Donor::query()->orderBy('id', 'DESC')->limit(1)->getAll()[0];

        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'stripe';
        $p->gateway_subscription_id = 'sub_pii_1';
        $p->gateway_customer_id     = 'cus_pii';
        $p->amount_cents            = 2500;
        $p->currency                = 'USD';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->started_at              = '2026-08-01 00:00:00';
        $p->next_payment_at         = '2026-09-01 00:00:00';
        $p->created_at              = '2026-08-01 00:00:00';
        $p->save();

        return (int) $donor->id;
    }

    public function test_the_list_withholds_the_donor_email_without_view_donors(): void
    {
        $this->seedPlanForDonor();
        $this->actAs(['dono_view_donations']);

        $req = new WP_REST_Request('GET', '/dono/v1/admin/recurring');
        $req->set_query_params(['page' => 1, 'per_page' => 25]);
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), 'the plans list is still readable');
        $rows = (array) $res->get_data();
        $this->assertCount(1, $rows);
        $this->assertSame('Noor Haddad', $rows[0]['donor']['name'], 'the display name stays, so the screen works');
        $this->assertNull($rows[0]['donor']['email'], 'email is the donor record');
    }

    public function test_view_donors_still_reads_the_email(): void
    {
        $this->seedPlanForDonor();
        $this->actAs(['dono_view_donations', 'dono_view_donors']);

        $req = new WP_REST_Request('GET', '/dono/v1/admin/recurring');
        $req->set_query_params(['page' => 1, 'per_page' => 25]);
        $rows = (array) rest_do_request($req)->get_data();

        $this->assertSame('noor.haddad@example.org', $rows[0]['donor']['email']);
    }
}
