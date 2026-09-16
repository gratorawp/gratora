<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\AdminGlobals;
use Gratora\Foundation\License\LicenseService;
use Gratora\Rest\Admin\DonationsController;
use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * Two places a capability was assumed rather than checked: the admin payload,
 * which any logged-in reader can ask for by typing a slug, and the donation
 * write routes, which handed back the read payload the read route gates.
 */
final class AdminPayloadNeedsAdminAccessTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Inline script data is printed once per handle on the global registry.
        $GLOBALS['wp_scripts'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['plugin_page']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function injected(): string
    {
        $GLOBALS['plugin_page'] = 'gratora';

        ob_start();
        (new AdminGlobals(Plugin::instance()->container->get(LicenseService::class)))->inject();
        wp_print_scripts();

        return (string) ob_get_clean();
    }

    public function test_a_subscriber_typing_the_slug_gets_no_payload(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertFalse(str_contains($this->injected(), 'window.gratora'), 'the payload was printed for a subscriber');
    }

    public function test_an_administrator_still_gets_it(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->assertTrue(str_contains($this->injected(), 'window.gratora'), 'the payload never reached an administrator');
    }

    public function test_marking_paid_answers_without_the_read_payload(): void
    {
        $donation = $this->pendingDonation();

        $role = 'gratora_refunder_' . uniqid();
        add_role($role, 'Refunder', ['read' => true, 'gratora_refund_donations' => true]);
        wp_set_current_user(self::factory()->user->create(['role' => $role]));

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/donations/' . $donation->reference . '/mark-paid');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $data = (array) $res->get_data();
        $this->assertSame('paid', $data['status'] ?? '');
        $this->assertArrayNotHasKey('donor', $data, 'the write route is not a way around the read capability');

        remove_role($role);
    }

    public function test_a_reader_who_may_do_both_still_gets_the_detail(): void
    {
        $donation = $this->pendingDonation();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/donations/' . $donation->reference . '/mark-paid');
        $data = (array) rest_do_request($req)->get_data();

        $this->assertArrayHasKey('donor', $data);
    }

    private function pendingDonation(): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference    = 'DN-CAP-' . strtoupper(substr(uniqid(), -6));
        $d->donor_id     = 1;
        $d->amount_cents = 2500;
        $d->net_cents    = 2500;
        $d->currency     = 'USD';
        $d->base_amount_cents = 2500;
        $d->base_currency     = 'USD';
        $d->fx_rate      = '1.00000000';
        $d->gateway      = 'offline';
        $d->status       = 'pending';
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        return $d;
    }
}
