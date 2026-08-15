<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorAvatars;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Settings\SettingsService;

/**
 * Gravatar sends a hash of the donor's address to a third party from the
 * visitor's browser, on a public page. The rules that keep that honest are the
 * ones worth pinning: off unless asked for, and never for someone who asked to
 * be anonymous.
 */
final class DonorAvatarsTest extends IntegrationTestCase
{
    private function avatars(): DonorAvatars
    {
        return Plugin::instance()->container->get(DonorAvatars::class);
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function enable(bool $on): void
    {
        $this->settings()->update('privacy', ['gravatar_avatars' => $on]);
    }

    private function donor(int $id, string $email = 'donor@example.com'): Donor
    {
        $crypto = Plugin::instance()->container->get(Crypto::class);

        $d = Donor::make();
        $d->id = $id;
        $d->email_encrypted = $crypto->encrypt($email);

        return $d;
    }

    protected function tearDown(): void
    {
        delete_option('dono_privacy');
        parent::tearDown();
    }

    public function test_it_is_off_until_the_org_turns_it_on(): void
    {
        $this->assertFalse($this->avatars()->enabled(), 'a fresh install must not call gravatar');
        $this->assertSame([], $this->avatars()->urlsFor([7 => $this->donor(7)]));
    }

    public function test_an_enabled_org_gets_a_url_per_donor(): void
    {
        $this->enable(true);

        $urls = $this->avatars()->urlsFor([7 => $this->donor(7)]);

        $this->assertArrayHasKey(7, $urls);
        $this->assertStringContainsString('gravatar.com', $urls[7]);
    }

    /**
     * The donation is already hidden; a picture would put a face on it, and
     * request itself would leak the address hash of someone who opted out.
     */
    public function test_an_anonymous_donor_never_gets_one(): void
    {
        $this->enable(true);

        $urls = $this->avatars()->urlsFor([7 => $this->donor(7)], [7 => true]);

        $this->assertArrayNotHasKey(7, $urls);
    }

    public function test_a_donor_with_no_stored_address_is_skipped(): void
    {
        $this->enable(true);

        $blank = Donor::make();
        $blank->id = 9;
        $blank->email_encrypted = '';

        $this->assertArrayNotHasKey(9, $this->avatars()->urlsFor([9 => $blank]));
    }

    /** Two donors, one anonymous: the other is unaffected. */
    public function test_one_anonymous_donor_does_not_suppress_the_rest(): void
    {
        $this->enable(true);

        $urls = $this->avatars()->urlsFor(
            [1 => $this->donor(1, 'a@example.com'), 2 => $this->donor(2, 'b@example.com')],
            [1 => true]
        );

        $this->assertArrayNotHasKey(1, $urls);
        $this->assertArrayHasKey(2, $urls);
    }
}
