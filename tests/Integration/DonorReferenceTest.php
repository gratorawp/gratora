<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * The donor reference is what the admin reads out over the phone and quotes in
 * a receipt. It is derived from the id rather than stored, and it was spelled
 * out at each call site, so the list could show one format while the profile
 * showed another.
 */
final class DonorReferenceTest extends IntegrationTestCase
{
    private function actAs(array $caps): void
    {
        $uid  = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $uid);
        foreach ($caps as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($uid);
    }

    private function donor(string $email): Donor
    {
        return Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Nadia', 'last_name' => 'Petrova']);
    }

    public function test_it_is_the_padded_id(): void
    {
        $donor = $this->donor('ref@example.com');

        $this->assertSame(sprintf('DONOR_%04d', (int) $donor->id), $donor->reference());
    }

    public function test_it_keeps_four_digits_and_grows_past_them(): void
    {
        $donor = $this->donor('width@example.com');

        $donor->id = 7;
        $this->assertSame('DONOR_0007', $donor->reference());

        $donor->id = 123456;
        $this->assertSame('DONOR_123456', $donor->reference());
    }

    public function test_the_list_shows_the_same_reference_as_the_profile(): void
    {
        $donor = $this->donor('both@example.com');
        $id    = (int) $donor->id;

        $this->actAs(['fundkit_view_donors']);

        $list = rest_do_request(new WP_REST_Request('GET', '/fundkit/v1/admin/donors'))->get_data();
        $row  = null;
        foreach ((array) ($list['items'] ?? $list) as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $row = $item;
                break;
            }
        }

        $this->assertIsArray($row, 'the donor was not in the list');
        $this->assertSame($donor->reference(), $row['reference'] ?? null);
    }
}
