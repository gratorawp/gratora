<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * The donors list offers Delete on a row and the route decides whether it
 * happens. The two have to ask the same question: the stored counters are paid
 * money only, so a donor left behind by an abandoned or refunded attempt looks
 * empty on the screen and is undeletable to the server.
 */
final class DonorDeleteEligibilityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** Creates a donor through the donation route and leaves the donation $status. */
    private function donorWithDonation(string $email, string $status): Donor
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => 4000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Rae', 'last_name' => 'Okonkwo'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $donation = Donation::query()->where('reference', $reference)->find('reference', $reference);
        $donation->status = $status;
        $donation->save();

        return $this->donors()->findByEmail($email);
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    /** @return array<int,array<string,mixed>> keyed by donor id */
    private function listRows(): array
    {
        $req = new WP_REST_Request('GET', '/dono/v1/admin/donors');
        $req->set_query_params(['page' => 1, 'per_page' => 100]);

        $out = [];
        foreach ((array) rest_do_request($req)->get_data() as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    private function deleteStatus(int $donorId): int
    {
        return rest_do_request(new WP_REST_Request('DELETE', "/dono/v1/admin/donors/{$donorId}"))->get_status();
    }

    public function test_a_donor_whose_only_donation_never_completed_is_not_offered_delete(): void
    {
        $donor = $this->donorWithDonation('rae.pending@example.org', 'pending');

        $row = $this->listRows()[(int) $donor->id];
        $this->assertSame(0, (int) $row['donations_count'], 'the counters see nothing, which is what made this row look deletable');
        $this->assertFalse($row['is_test_only'], 'the donation is live, so the test badge does not cover it either');
        $this->assertFalse($row['deletable'], 'the row must not offer an action that can only fail');

        $this->assertSame(409, $this->deleteStatus((int) $donor->id), 'and the route agrees');
    }

    public function test_a_donor_whose_only_donation_was_refunded_is_not_offered_delete(): void
    {
        $donor = $this->donorWithDonation('rae.refunded@example.org', 'refunded');

        $this->assertFalse($this->listRows()[(int) $donor->id]['deletable']);
        $this->assertSame(409, $this->deleteStatus((int) $donor->id));
    }

    public function test_a_donor_with_no_donation_row_at_all_is_offered_delete_and_deletes(): void
    {
        $donor = $this->donors()->findOrCreate('rae.bare@example.org', ['first_name' => 'Rae']);

        $this->assertTrue($this->listRows()[(int) $donor->id]['deletable']);
        $this->assertSame(200, $this->deleteStatus((int) $donor->id));
    }
}
