<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Donors\Donor;
use GiveFlow\Donors\DonorRepository;
use GiveFlow\Donors\Privacy\WordPressPrivacy;
use GiveFlow\Foundation\Identity\IdentityHasher;
use GiveFlow\Foundation\Plugin;

/**
 * Tools, Export Personal Data and Erase Personal Data are the screens a site
 * owner is pointed at when a request arrives, and what an auditor looks at.
 * They answered nothing for donors, which is not a smaller version of working.
 */
final class WordPressPrivacyTest extends IntegrationTestCase
{
    private function privacy(): WordPressPrivacy
    {
        $c = Plugin::instance()->container;

        return new WordPressPrivacy(
            $c->get(DonorRepository::class),
            $c->get(\GiveFlow\Donors\DonorService::class),
            $c->get(IdentityHasher::class),
        );
    }

    private function makeDonor(string $email): Donor
    {
        $c    = Plugin::instance()->container;
        $now  = gmdate('Y-m-d H:i:s');
        $hash = $c->get(IdentityHasher::class)->emailHash($email);

        $donor = Donor::make();
        $donor->email_hash      = $hash;
        $donor->email_encrypted = $c->get(\GiveFlow\Foundation\Crypto\Crypto::class)->encrypt($email);
        $donor->first_name      = 'Ada';
        $donor->last_name       = 'Lovelace';
        $donor->created_at      = $now;
        $donor->updated_at      = $now;
        $donor->save();

        return $donor;
    }

    public function test_wordpress_is_told_about_both_tools(): void
    {
        $this->privacy()->register();

        $this->assertArrayHasKey('giveflow', apply_filters('wp_privacy_personal_data_exporters', []));
        $this->assertArrayHasKey('giveflow', apply_filters('wp_privacy_personal_data_erasers', []));
    }

    public function test_an_export_returns_the_donor_the_email_belongs_to(): void
    {
        $email = 'export-' . uniqid() . '@example.test';
        $this->makeDonor($email);

        $export = $this->privacy()->export($email);

        $this->assertTrue($export['done']);
        $values = array_column($export['data'][0]['data'] ?? [], 'value');
        $this->assertContains('Ada', $values);
        $this->assertContains('Lovelace', $values);
    }

    /** An address nobody donated with is not an error, it is an empty answer. */
    public function test_an_unknown_email_exports_nothing(): void
    {
        $export = $this->privacy()->export('nobody-' . uniqid() . '@example.test');

        $this->assertSame([], $export['data']);
        $this->assertTrue($export['done']);
    }

    /**
     * The whole point: WordPress's eraser has to reach the same erasure the
     * admin button runs, not a second implementation of it.
     */
    public function test_erasing_through_wordpress_redacts_the_donor(): void
    {
        $email = 'erase-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);

        $result = $this->privacy()->erase($email);

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);

        $after = (new DonorRepository())->findById((int) $donor->id);
        $this->assertNotNull($after->redacted_at, 'the donor was not actually erased');
        $this->assertNull($after->first_name);
    }

    /**
     * Donations survive an erasure and stop naming anyone, so the eraser has to
     * say something was retained. Answering "everything removed" would be a
     * false statement on a compliance screen.
     */
    public function test_it_admits_the_donations_are_kept(): void
    {
        $email = 'kept-' . uniqid() . '@example.test';
        $this->makeDonor($email);

        $result = $this->privacy()->erase($email);

        $this->assertTrue($result['items_retained']);
        $this->assertNotSame([], $result['messages']);
    }

    public function test_erasing_twice_is_not_an_error(): void
    {
        $email = 'twice-' . uniqid() . '@example.test';
        $this->makeDonor($email);

        $this->privacy()->erase($email);
        $second = $this->privacy()->erase($email);

        $this->assertTrue($second['done']);
        $this->assertFalse($second['items_removed']);
    }
}
