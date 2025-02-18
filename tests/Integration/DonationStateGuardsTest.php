<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use WP_REST_Request;

/**
 * Pins the donation status-transition guards: refund states are terminal.
 * A replayed gateway webhook, a re-confirm, or a mark-failed must never
 * resurrect or mutate a refunded / partially refunded donation, and a
 * redelivered webhook must dedupe quietly instead of erroring.
 */
final class DonationStateGuardsTest extends IntegrationTestCase
{
    public function test_confirm_is_a_noop_on_a_refunded_donation(): void
    {
        $donation = $this->driveDonationToPaid();
        $this->donationService()->refund($donation, $donation->amount_cents, 'donor requested');
        $refunded = $this->donations()->findByReference($donation->reference);
        $this->assertSame('refunded', $refunded->status);
        $paidAt = $refunded->paid_at;

        $this->donationService()->confirm($refunded, [
            'gateway_txn_id' => 'replayed-evt',
            'payment_method' => 'card',
        ]);

        $after = $this->donations()->findByReference($donation->reference);
        $this->assertSame('refunded', $after->status);
        $this->assertSame($paidAt, $after->paid_at);
        $this->assertNotSame('replayed-evt', $after->gateway_txn_id);
    }

    public function test_mark_failed_is_a_noop_on_a_partially_refunded_donation(): void
    {
        $donation = $this->driveDonationToPaid();
        $this->donationService()->refund($donation, 2000, 'partial');
        $partial = $this->donations()->findByReference($donation->reference);
        $this->assertSame('partial_refund', $partial->status);

        $this->donationService()->markFailed($partial, 'should not apply');

        $after = $this->donations()->findByReference($donation->reference);
        $this->assertSame('partial_refund', $after->status);
        $this->assertNull($after->failure_reason);
    }

    public function test_public_confirm_route_rejects_a_refunded_donation_with_422(): void
    {
        $donation = $this->driveDonationToPaid();
        $this->donationService()->refund($donation, $donation->amount_cents);

        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$donation->reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $res = rest_do_request($req);

        $this->assertSame(422, $res->get_status());
        $this->assertSame('refunded', $this->donations()->findByReference($donation->reference)->status);
    }

    public function test_public_confirm_route_is_idempotent_on_paid(): void
    {
        $donation = $this->driveDonationToPaid();
        $txn = $donation->gateway_txn_id;

        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$donation->reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $this->assertSame('paid', $res->get_data()['status']);

        $after = $this->donations()->findByReference($donation->reference);
        $this->assertSame('paid', $after->status);
        $this->assertSame($txn, $after->gateway_txn_id);
    }

    public function test_admin_mark_failed_rejects_a_refunded_donation_with_422(): void
    {
        $donation = $this->driveDonationToPaid();
        $this->donationService()->refund($donation, $donation->amount_cents);

        $req = new WP_REST_Request('POST', "/dono/v1/admin/donations/{$donation->reference}/mark-failed");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $res = rest_do_request($req);

        $this->assertSame(422, $res->get_status());
        $this->assertSame('refunded', $this->donations()->findByReference($donation->reference)->status);
    }

    public function test_duplicate_webhook_delivery_dedupes_quietly(): void
    {
        $first  = rest_do_request(new WP_REST_Request('POST', '/dono/v1/webhooks/offline'));
        $second = rest_do_request(new WP_REST_Request('POST', '/dono/v1/webhooks/offline'));

        $this->assertLessThan(500, $first->get_status());
        $this->assertLessThan(500, $second->get_status(), 'Redelivery must not surface as a server error');

        $count = (int) self::$wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::$prefix . "dono_webhooks_log WHERE gateway = 'offline'"
        );
        $this->assertSame(1, $count, 'Redelivery must dedupe to a single log row');
    }

    /** Create an offline donation via the public API and confirm it. */
    private function driveDonationToPaid(): Donation
    {
        $createReq = new WP_REST_Request('POST', '/dono/v1/donations');
        $createReq->set_header('content-type', 'application/json');
        $createReq->set_body(json_encode([
            'email'        => 'guard@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Gail', 'country' => 'US'],
        ]));
        $reference = rest_do_request($createReq)->get_data()['reference'];

        $confirmReq = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $confirmReq->set_header('content-type', 'application/json');
        $confirmReq->set_body('{}');
        rest_do_request($confirmReq);

        $this->runPendingAsyncJobs();

        return $this->donations()->findByReference($reference);
    }

    private function donationService(): DonationService
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): DonationRepository
    {
        return \Dono\Foundation\Plugin::instance()->container->get(DonationRepository::class);
    }
}
