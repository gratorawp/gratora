<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The encrypted phone and address columns are the only copy the site holds of
 * either. A patch that carried one of them as something other than text used
 * to coerce it to empty, so a donor editing their first name lost the number
 * and the route still answered 200 with the empty field showing.
 */
final class PortalProfilePhoneTest extends IntegrationTestCase
{
    private string $csrf = '';

    protected function tearDown(): void
    {
        unset($_COOKIE['gratora_donor_session']);
        parent::tearDown();
    }

    private function signedInDonor(): Donor
    {
        $donor = $this->donors()->findOrCreate('phone-' . uniqid() . '@example.test', ['first_name' => 'Ada']);

        $this->csrf = bin2hex(random_bytes(8));
        $_COOKIE['gratora_donor_session'] = $this->portalSession((int) $donor->id, $this->csrf);

        return $donor;
    }

    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(array $body): int
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/portal/profile');
        $req->set_header('X-Gratora-Csrf', $this->csrf);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string) wp_json_encode($body));

        return rest_do_request($req)->get_status();
    }

    private function storedPhone(int $id): ?string
    {
        return $this->donors()->decryptPhone(Donor::query()->where('id', $id)->get());
    }

    public function test_a_mistyped_phone_does_not_erase_the_stored_one(): void
    {
        $donor = $this->signedInDonor();

        $this->assertSame(200, $this->patch(['phone' => '+441632960111']));
        $this->assertSame(422, $this->patch(['first_name' => 'Ada', 'phone' => 5551234]));
        $this->assertSame('+441632960111', $this->storedPhone((int) $donor->id));
    }

    /**
     * The portal route carries no address field today, so the service is where
     * this is reachable: the same coercion sits on the address column, whose
     * ciphertext is likewise the only copy.
     */
    public function test_a_mistyped_address_does_not_erase_the_stored_one(): void
    {
        $donor = $this->signedInDonor();
        $this->donors()->editProfile($donor, ['address' => ['line1' => '1 Long Water', 'city' => 'Leeds']]);

        $stored = Donor::query()->where('id', (int) $donor->id)->get()->address_encrypted;
        $this->assertNotNull($stored);

        try {
            $this->donors()->editProfile($donor, ['address' => 'somewhere']);
            $this->fail('a non-address should be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($stored, Donor::query()->where('id', (int) $donor->id)->get()->address_encrypted);
        }
    }

    /** The guard must not refuse the clear the portal's own empty field sends. */
    public function test_an_empty_phone_still_clears_it(): void
    {
        $donor = $this->signedInDonor();

        $this->assertSame(200, $this->patch(['phone' => '+441632960111']));
        $this->assertSame(200, $this->patch(['phone' => '']));
        $this->assertNull($this->storedPhone((int) $donor->id));
    }

    public function test_a_null_phone_still_clears_it(): void
    {
        $donor = $this->signedInDonor();

        $this->assertSame(200, $this->patch(['phone' => '+441632960111']));
        $this->assertSame(200, $this->patch(['phone' => null]));
        $this->assertNull($this->storedPhone((int) $donor->id));
    }
}
