<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The org can turn account deletion and data export off. The portal hides the
 * buttons, but hiding a button is a courtesy: the routes are what has to
 * refuse, because the caller writes the request.
 */
final class PortalPrivacyTogglesTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['gratora_donor_session']);
        delete_option('gratora_privacy');
        parent::tearDown();
    }

    private string $csrf = '';

    private function signedInDonor(): Donor
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('toggle-' . uniqid() . '@example.test', ['first_name' => 'Sam']);

        $this->csrf = bin2hex(random_bytes(8));
        $_COOKIE['gratora_donor_session'] = $this->portalSession((int) $donor->id, $this->csrf);

        return $donor;
    }

    /** Portal writes sit behind sessionWithCsrf, so a write without the header never reaches the route. */
    private function write(string $route, array $body = []): int
    {
        $req = new WP_REST_Request('POST', $route);
        $req->set_header('X-Gratora-Csrf', $this->csrf);
        $req->set_body_params($body);

        return rest_do_request($req)->get_status();
    }

    private function forget(): int
    {
        return $this->write('/gratora/v1/portal/forget', ['confirm' => 'DELETE']);
    }

    /**
     * The audit row is the only account of who erased an account, and the WP
     * user in the same browser has nothing to do with it: a donor signed in to
     * both would be filed as staff erasing someone else.
     */
    public function test_the_portal_erasure_is_recorded_as_the_donor_even_for_a_logged_in_user(): void
    {
        $donor = $this->signedInDonor();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->assertSame(200, $this->forget());

        $row = Event::query()
            ->where('type', 'donor.redacted')
            ->where('donor_id', (int) $donor->id)
            ->get();

        $this->assertNotNull($row);
        $payload = is_array($row->payload) ? $row->payload : (array) json_decode((string) $row->payload, true);
        $this->assertSame('donor', $payload['by'] ?? '');
        $this->assertSame('', $payload['actor_name'] ?? 'x');
    }

    public function test_deletion_is_refused_when_the_org_turned_it_off(): void
    {
        $donor = $this->signedInDonor();
        update_option('gratora_privacy', ['allow_account_delete' => false]);

        $this->assertSame(403, $this->forget());
        $this->assertNull(
            Donor::query()->find('id', (int) $donor->id)->redacted_at,
            'a refused deletion must not have erased anything'
        );
    }

    public function test_export_is_refused_when_the_org_turned_it_off(): void
    {
        $this->signedInDonor();
        update_option('gratora_privacy', ['allow_data_export' => false]);

        $this->assertSame(403, $this->write('/gratora/v1/portal/data-export'));
    }

    public function test_deletion_still_works_when_it_is_left_on(): void
    {
        $donor = $this->signedInDonor();

        $this->assertSame(200, $this->forget());
        $this->assertNotNull(Donor::query()->find('id', (int) $donor->id)->redacted_at);
    }

    public function test_the_session_response_carries_both_toggles(): void
    {
        $this->signedInDonor();
        update_option('gratora_privacy', ['allow_account_delete' => false, 'allow_data_export' => true]);

        $me = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/portal/me'))->get_data();

        $this->assertFalse($me['allow_account_delete']);
        $this->assertTrue($me['allow_data_export']);
    }
}
