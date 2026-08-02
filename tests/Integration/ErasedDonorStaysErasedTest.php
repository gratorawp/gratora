<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use InvalidArgumentException;

/**
 * Erasure has to be a one-way door.
 *
 * refreshProfile only fills fields that are empty, which made an erased donor
 * the ideal target rather than a protected one: erasure nulls exactly the
 * fields it fills. Writing them back left redacted_at set, and redact()
 * early-returns on an already-redacted row, so the erasure could never be run
 * again to undo it.
 */
final class ErasedDonorStaysErasedTest extends IntegrationTestCase
{
    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function erasedDonor(): Donor
    {
        $donor = $this->service()->findOrCreate('erased-' . uniqid() . '@example.com', [
            'first_name' => 'Anna',
            'last_name'  => 'Bell',
            'company'    => 'Acme',
            'country'    => 'DE',
        ]);

        $this->service()->redact($donor);

        return (new DonorRepository())->findById((int) $donor->id);
    }

    public function test_refresh_profile_refuses_an_erased_donor(): void
    {
        $donor = $this->erasedDonor();
        $this->assertNotNull($donor->redacted_at, 'precondition: the donor is erased');

        $this->expectException(InvalidArgumentException::class);

        $this->service()->refreshProfile($donor, [
            'first_name' => 'Anna',
            'last_name'  => 'Bell',
            'company'    => 'Acme',
            'country'    => 'DE',
        ]);
    }

    public function test_the_erased_row_is_untouched_after_the_attempt(): void
    {
        $donor = $this->erasedDonor();

        try {
            $this->service()->refreshProfile($donor, [
                'first_name' => 'Anna',
                'last_name'  => 'Bell',
                'company'    => 'Acme',
                'country'    => 'DE',
                'phone'      => '+49 30 123456',
            ]);
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $reloaded = (new DonorRepository())->findById((int) $donor->id);

        $this->assertEmpty($reloaded->first_name, 'the name stays gone');
        $this->assertEmpty($reloaded->last_name);
        $this->assertEmpty($reloaded->company);
        $this->assertEmpty($reloaded->phone_encrypted);
        // country survives redact() on purpose: a country on its own does not
        // identify anyone and the totals stay reportable by geography.
        $this->assertSame('DE', $reloaded->country);
        $this->assertNotNull($reloaded->redacted_at, 'and it is still marked erased');
    }

    public function test_a_genuine_new_donation_still_reactivates_and_back_fills(): void
    {
        $email = 'return-' . uniqid() . '@example.com';

        $donor = $this->service()->findOrCreate($email, ['first_name' => 'Cara', 'last_name' => 'Diaz']);
        $this->service()->redact($donor);

        // The one path that may bring a donor back. findOrCreate clears
        // redacted_at before it back-fills, so the guard must not block it.
        $returned = $this->service()->findOrCreate($email, [
            'first_name' => 'Cara',
            'last_name'  => 'Diaz',
        ], true);

        $this->assertNull($returned->redacted_at, 'the donor is active again');
        $this->assertSame('Cara', $returned->first_name, 'and their details came back with the donation');
        $this->assertSame((int) $donor->id, (int) $returned->id, 'still one donor per email');
    }

    public function test_a_bare_lookup_leaves_an_erased_donor_alone(): void
    {
        $email = 'lookup-' . uniqid() . '@example.com';

        $donor = $this->service()->findOrCreate($email, ['first_name' => 'Eve', 'last_name' => 'Fox']);
        $this->service()->redact($donor);

        $found = $this->service()->findOrCreate($email, ['first_name' => 'Eve', 'last_name' => 'Fox']);

        $this->assertNotNull($found->redacted_at, 'an unauthenticated lookup never un-erases');
        $this->assertEmpty($found->first_name);
    }
}
