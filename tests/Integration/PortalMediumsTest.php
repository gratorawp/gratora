<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Donors\MagicLinkService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/** Portal surfaces that showed or spent the wrong thing. */
final class PortalMediumsTest extends IntegrationTestCase
{
    private object $donor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('portal-med-' . uniqid() . '@example.test');

        $sid = $this->portalSession((int) $this->donor->id, 'tok');
        $_COOKIE['dono_donor_session'] = $sid;
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['dono_donor_session']);
        parent::tearDown();
    }

    private function row(string $kind, string $reference): Donation
    {
        $d = Donation::make();
        $d->reference         = $reference;
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->kind              = $kind;
        $d->amount_cents      = 5000;
        $d->base_amount_cents = 5000;
        $d->currency          = 'USD';
        $d->is_test           = false;
        $d->donor_id          = (int) $this->donor->id;
        $d->created_at        = gmdate('Y-m-d H:i:s');
        $d->paid_at           = gmdate('Y-m-d H:i:s');
        $d->save();

        return $d;
    }

    public function test_a_ticket_order_is_not_listed_to_the_donor_as_a_donation(): void
    {
        $given = $this->row('donation', 'GAVE-' . uniqid());
        $order = $this->row('order', 'ORDER-' . uniqid());

        $refs = array_column(
            (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/portal/donations'))->get_data(),
            'reference'
        );

        $this->assertContains((string) $given->reference, $refs);
        $this->assertNotContains(
            (string) $order->reference,
            $refs,
            'a ticket purchase is not a donation the donor made'
        );
    }

    public function test_a_ticket_order_cannot_be_opened_through_the_donation_detail(): void
    {
        $order = $this->row('order', 'ORDER-' . uniqid());

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/portal/donations/' . $order->reference));

        $this->assertSame(404, $res->get_status(), 'excluded from the list means excluded from the detail');
    }

    public function test_downloading_receipts_does_not_spend_the_sign_in_budget(): void
    {
        // The limit exists to stop token guessing. Counting successes, across
        // one budget shared by every purpose, meant a donor opening their
        // receipts tab locked themselves out of signing in and were told their
        // link had expired.
        $links = Plugin::instance()->container->get(MagicLinkService::class);

        for ($i = 0; $i < 30; $i++) {
            $token = $links->issue((int) $this->donor->id, 'download_receipt', $i + 1, 3600);
            $this->assertNotNull(
                $links->validate($token, 'download_receipt', $i + 1),
                "receipt download {$i} still works"
            );
        }

        $signIn = $links->issue((int) $this->donor->id, 'portal_login', null, 3600);
        $this->assertNotNull(
            $links->validate($signIn, 'portal_login'),
            'and the donor can still sign in'
        );
    }

    public function test_guessing_is_still_stopped(): void
    {
        $links = Plugin::instance()->container->get(MagicLinkService::class);

        for ($i = 0; $i < 25; $i++) {
            $links->validate('not-a-real-token-' . $i, 'portal_login');
        }

        $real = $links->issue((int) $this->donor->id, 'portal_login', null, 3600);
        $this->assertNull(
            $links->validate($real, 'portal_login'),
            'after enough failed guesses the purpose is locked, even for a good token'
        );
    }
}
