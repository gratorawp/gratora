<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * editProfile() must write (and fire dono.donor.updated) only on a real change.
 * The phone/address branches used to force $changed = true and also issue their
 * own direct UPDATE, so any request merely including a phone/address key wrote
 * twice and re-ran every donor.updated listener on a no-op edit.
 */
final class DonorProfileEditTest extends IntegrationTestCase
{
    private function svc(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    public function test_unchanged_phone_does_not_fire_donor_updated(): void
    {
        $svc   = $this->svc();
        $donor = $svc->findOrCreate('edit@example.com', ['first_name' => 'Ed']);
        $svc->editProfile($donor, ['phone' => '+1 555 0100']); // establishes the phone

        $fired = 0;
        add_action('dono.donor.updated', function () use (&$fired): void {
            $fired++;
        });

        $svc->editProfile($donor, ['phone' => '+1 555 0100']); // same value -> no-op
        $this->assertSame(0, $fired, 'an unchanged phone does not fire donor.updated');

        $svc->editProfile($donor, ['phone' => '+1 555 0199']); // genuine change
        $this->assertSame(1, $fired, 'a real change fires exactly once');

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertSame('+1 555 0199', $svc->decryptPhone($fresh), 'the new phone persisted');
    }

    public function test_unchanged_text_field_does_not_refire(): void
    {
        $svc   = $this->svc();
        $donor = $svc->findOrCreate('edit2@example.com', ['first_name' => 'Ann']);

        $fired = 0;
        add_action('dono.donor.updated', function () use (&$fired): void {
            $fired++;
        });

        $svc->editProfile($donor, ['first_name' => 'Anne']);
        $this->assertSame(1, $fired);

        $svc->editProfile($donor, ['first_name' => 'Anne']); // no-op
        $this->assertSame(1, $fired, 'an unchanged name does not re-fire');

        $fresh = Donor::query()->where('id', (int) $donor->id)->get();
        $this->assertSame('Anne', $fresh->first_name);
    }
}
