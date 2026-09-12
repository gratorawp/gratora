<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Receipts\Receipt;
use WP_REST_Request;

/**
 * The donors list offers Delete on a row and the route decides whether it
 * happens. The two have to ask the same question, and the stored counters are
 * not that question: they are paid money only, so a donor left behind by an
 * abandoned or refunded attempt looks empty on the screen whatever the answer
 * turns out to be.
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
        $create = new WP_REST_Request('POST', '/gratora/v1/donations');
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
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/donors');
        $req->set_query_params(['page' => 1, 'per_page' => 100]);

        $out = [];
        foreach ((array) rest_do_request($req)->get_data() as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    private function deleteStatus(int $donorId): int
    {
        $request = new WP_REST_Request('DELETE', "/gratora/v1/admin/donors/{$donorId}");
        $request->set_param('confirmation', 'DELETE');

        return rest_do_request($request)->get_status();
    }

    public function test_a_donor_left_by_an_unfinished_checkout_is_offered_delete_and_deletes(): void
    {
        $donor = $this->donorWithDonation('rae.pending@example.org', 'pending');

        $row = $this->listRows()[(int) $donor->id];
        $this->assertSame(0, (int) $row['donations_count'], 'the counters see nothing, whatever the gate says');
        $this->assertFalse($row['is_test_only'], 'the donation is live, so the test badge does not cover it either');
        $this->assertTrue($row['deletable'], 'the row offers what the route will do');

        $this->assertSame(200, $this->deleteStatus((int) $donor->id), 'and the route agrees');
    }

    public function test_a_donor_whose_only_donation_was_refunded_is_offered_delete_and_deletes(): void
    {
        $donor = $this->donorWithDonation('rae.refunded@example.org', 'refunded');

        $this->assertTrue($this->listRows()[(int) $donor->id]['deletable']);
        $this->assertSame(200, $this->deleteStatus((int) $donor->id));
    }

    /**
     * The gate's answer is a sentence, and the screen is the only place it can
     * be read. Sending the boolean alone leaves an operator looking at a row
     * with no delete on it and nothing at all saying why, which is
     * indistinguishable from the feature not existing.
     */
    public function test_a_row_that_cannot_be_deleted_carries_the_reason(): void
    {
        $donor    = $this->donorWithDonation('rae.receipted@example.org', 'paid');
        $donation = Donation::query()->where('donor_id', (int) $donor->id)->get();

        $r = Receipt::make();
        $r->donation_id    = (int) $donation->id;
        $r->renderer_id    = 'receipt';
        $r->receipt_number = 'R-' . bin2hex(random_bytes(3));
        $r->locale         = 'en_US';
        $r->voided         = false;
        $r->issued_at      = gmdate('Y-m-d H:i:s');
        $r->save();

        $row = $this->listRows()[(int) $donor->id];

        $this->assertFalse($row['deletable']);
        $this->assertStringContainsString('receipt', strtolower((string) ($row['delete_blocked'] ?? '')));
    }

    public function test_a_row_that_can_be_deleted_carries_no_reason(): void
    {
        $donor = $this->donors()->findOrCreate('rae.clear@example.org', ['first_name' => 'Rae']);

        $row = $this->listRows()[(int) $donor->id];

        $this->assertTrue($row['deletable']);
        $this->assertNull($row['delete_blocked']);
    }

    public function test_a_donor_with_no_donation_row_at_all_is_offered_delete_and_deletes(): void
    {
        $donor = $this->donors()->findOrCreate('rae.bare@example.org', ['first_name' => 'Rae']);

        $this->assertTrue($this->listRows()[(int) $donor->id]['deletable']);
        $this->assertSame(200, $this->deleteStatus((int) $donor->id));
    }
}
