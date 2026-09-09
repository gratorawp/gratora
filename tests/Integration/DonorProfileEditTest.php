<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;

/**
 * editProfile() must write (and fire gratora.donor.updated) only on a real
 * change: a request that merely includes a phone or address key is not an edit,
 * and treating it as one re-runs every donor.updated listener on a no-op.
 */
final class DonorProfileEditTest extends IntegrationTestCase
{
    private function svc(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    public function test_a_multibyte_name_survives_the_length_clamp(): void
    {
        $svc   = $this->svc();
        $donor = $svc->findOrCreate('kanji-portal@example.test', ['first_name' => 'Ken']);
        $name  = str_repeat('東', 40);

        $svc->editProfile($donor, ['last_name' => $name]);

        $this->assertSame($name, (string) Donor::query()->find('id', (int) $donor->id)->last_name);
    }

    public function test_unchanged_phone_does_not_fire_donor_updated(): void
    {
        $svc   = $this->svc();
        $donor = $svc->findOrCreate('edit@example.com', ['first_name' => 'Ed']);
        $svc->editProfile($donor, ['phone' => '+1 555 0100']); // establishes the phone

        $fired = 0;
        add_action('gratora.donor.updated', function () use (&$fired): void {
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
        add_action('gratora.donor.updated', function () use (&$fired): void {
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
