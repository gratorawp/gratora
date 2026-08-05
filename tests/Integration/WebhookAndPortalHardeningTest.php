<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;
use Dono\Gateways\WebhookPaymentGuard;
use WP_REST_Request;

/**
 * Three ways a stranger could reach into someone else's record.
 *
 * A verified signature proves a processor sent the event, not that the event is
 * about a donation the sender is entitled to touch, and an endpoint that takes
 * no session proves nothing at all about who is calling it.
 */
final class WebhookAndPortalHardeningTest extends IntegrationTestCase
{
    private function paidDonation(bool $isTest, string $gateway = 'paypal'): Donation
    {
        $d = Donation::make();
        $d->reference         = 'REF-' . uniqid();
        $d->status            = 'paid';
        $d->gateway           = $gateway;
        $d->kind              = 'donation';
        $d->amount_cents      = 10000;
        $d->base_amount_cents = 10000;
        $d->currency          = 'USD';
        $d->is_test           = $isTest;
        $d->donor_id          = (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('guard-' . uniqid() . '@example.test')->id;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();

        return $d;
    }

    public function test_a_test_mode_credential_may_not_reverse_a_live_donation(): void
    {
        // The exact pair the two PayPal reversal handlers never checked. A
        // sandbox credential is a much softer secret than a live one: staging
        // env files, CI, a departed contractor.
        $live = $this->paidDonation(false);

        $this->assertNotNull(
            WebhookPaymentGuard::refuseToTouch($live, 'paypal', true),
            'a sandbox-verified event is refused against live money'
        );
        $this->assertNull(
            WebhookPaymentGuard::refuseToTouch($live, 'paypal', false),
            'the live credential is still allowed'
        );
    }

    public function test_one_gateways_event_may_not_reverse_anothers_donation(): void
    {
        $stripeDonation = $this->paidDonation(false, 'stripe');

        $this->assertNotNull(
            WebhookPaymentGuard::refuseToTouch($stripeDonation, 'paypal', false),
            'custom_id is chosen by whoever created the order, so the row it names must be checked'
        );
    }

    /** Anyone knowing an address could have written a name onto that donor. */
    public function test_register_does_not_write_a_name_onto_an_existing_donor(): void
    {
        $email = 'existing-' . uniqid() . '@example.test';

        // A donor who gave without supplying a name, which is what a form with
        // no name block produces.
        $donor = Plugin::instance()->container->get(DonorService::class)->findOrCreate($email);
        $this->assertNull($donor->first_name, 'seeded with no name');

        $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['email' => $email, 'name' => 'Rude Word']));
        rest_do_request($req);

        $fresh = Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get();

        $this->assertNull($fresh->first_name, 'a stranger cannot name someone else');
        $this->assertNull($fresh->last_name);
    }

    public function test_register_still_names_a_donor_it_creates(): void
    {
        $email = 'brand-new-' . uniqid() . '@example.test';

        $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['email' => $email, 'name' => 'Ada Lovelace']));
        rest_do_request($req);

        $fresh = Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get();

        $this->assertNotNull($fresh, 'the donor is created');
        $this->assertSame('Ada', (string) $fresh->first_name, 'and keeps the name they gave for themselves');
        $this->assertSame('Lovelace', (string) $fresh->last_name);
    }
}
