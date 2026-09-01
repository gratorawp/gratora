<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Donors\PublicDonorNames;
use FundKit\Foundation\Plugin;

/**
 * The rule every donor-facing surface shares: what a public page may print.
 *
 * It used to live in each block's render method - four of them across two
 * plugins - and two forgot it, so hiding a donor took their name off one page
 * and left it on another. Here it is one function, and it withholds by
 * returning the empty string every block already renders as "Anonymous", so a
 * caller is correct without knowing the rule exists.
 */
final class PublicDonorNamesTest extends IntegrationTestCase
{
    private function donor(string $email, string $first = 'Nadia', string $last = 'Petrova'): Donor
    {
        return Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => $first, 'last_name' => $last]);
    }

    private function hide(Donor $donor): Donor
    {
        $donor->public_hidden_at = gmdate('Y-m-d H:i:s');
        $donor->save();

        return Donor::query()->find('id', $donor->id);
    }

    public function test_a_visible_donor_is_named(): void
    {
        $this->assertSame('Nadia Petrova', PublicDonorNames::of($this->donor('a@example.com')));
    }

    public function test_a_hidden_donor_is_withheld(): void
    {
        $this->assertSame('', PublicDonorNames::of($this->hide($this->donor('b@example.com'))));
    }

    /**
     * The load-bearing choice: withholding returns the same value an unnamed
     * donor gives, which every caller already turns into "Anonymous". A
     * sentinel they had to recognise would just be the old bug with an extra
     * step.
     */
    public function test_withholding_looks_the_same_as_having_no_name(): void
    {
        $unnamed = PublicDonorNames::of($this->donor('c@example.com', '', ''));
        $hidden  = PublicDonorNames::of($this->hide($this->donor('d@example.com')));

        $this->assertSame($unnamed, $hidden);
    }

    public function test_a_missing_donor_is_withheld(): void
    {
        $this->assertSame('', PublicDonorNames::of(null));
    }

    public function test_the_batch_lookup_withholds_too(): void
    {
        $visible = $this->donor('e@example.com');
        $hidden  = $this->hide($this->donor('f@example.com', 'Zoltan', 'Quddus'));

        $names = PublicDonorNames::forIds([(int) $visible->id, (int) $hidden->id]);

        $this->assertSame('Nadia Petrova', $names[(int) $visible->id]);
        $this->assertSame('', $names[(int) $hidden->id]);
    }

    public function test_the_batch_lookup_handles_nothing_to_look_up(): void
    {
        $this->assertSame([], PublicDonorNames::forIds([]));
        $this->assertSame([], PublicDonorNames::forIds([0, -1]));
    }
}
