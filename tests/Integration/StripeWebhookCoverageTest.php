<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeAccount;
use WP_REST_Request;

/**
 * Coverage for the remaining Stripe webhook branches that had no test:
 * a failed PaymentIntent (donation → failed), and the two Connect account
 * lifecycle events (account.updated refreshes status; deauthorized forgets it).
 */
final class StripeWebhookCoverageTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret' => $this->secret, 'test_mode' => true],
        ]);

        $stripeAcct = (new StripeAccount(new Crypto()));
        $stripeAcct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

        $c       = Plugin::instance()->container;
        $manager = $c->get(\Dono\Gateways\GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Dono\Gateways\Stripe\StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(\Dono\Donations\DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(\Dono\Gateways\Stripe\StripeAccount::class),
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(\Dono\Donors\DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }
    }

    public function test_payment_intent_failed_marks_donation_failed(): void
    {
        $donation = $this->pendingStripeDonation();

        $this->postWebhook('payment_intent.payment_failed', [
            'id'                 => (string) $donation->gateway_intent_id,
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ]);

        $reloaded = Donation::query()->where('id', $donation->id)->get();
        $this->assertSame('failed', $reloaded->status, 'a failed PaymentIntent marks the donation failed');
    }

    public function test_account_updated_refreshes_connected_account_status(): void
    {
        $connect = new StripeAccount(new Crypto());
        $this->assertTrue($connect->canCharge(), 'account starts able to charge');

        $this->postWebhook('account.updated', [
            'id'              => 'acct_test_123',
            'charges_enabled' => false,
        ]);

        $this->assertFalse(
            (new StripeAccount(new Crypto()))->canCharge(),
            'account.updated refreshes the stored charges_enabled flag',
        );
    }

    public function test_account_updated_ignores_a_different_account(): void
    {
        $this->postWebhook('account.updated', [
            'id'              => 'acct_someone_else',
            'charges_enabled' => false,
        ]);

        $this->assertTrue(
            (new StripeAccount(new Crypto()))->canCharge(),
            'an update for an unrelated account does not touch ours',
        );
    }

    public function test_account_deauthorized_forgets_the_account(): void
    {
        $this->assertNotNull((new StripeAccount(new Crypto()))->accountId(), 'account is connected');

        $this->postWebhook('account.application.deauthorized', [], ['account' => 'acct_test_123']);

        $this->assertNull(
            (new StripeAccount(new Crypto()))->accountId(),
            'deauthorization drops the local Connect account',
        );
    }

    private function pendingStripeDonation(): Donation
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('stripe-fail-' . uniqid() . '@example.test', ['first_name' => 'Stripe', 'last_name' => 'Fail']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'STRIPE-FAIL-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->status            = 'pending';
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = 'pi_test_' . bin2hex(random_bytes(6));
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /** @param array<string,mixed> $envelopeExtra extra top-level event fields (e.g. account) */
    private function postWebhook(string $type, array $object, array $envelopeExtra = []): void
    {
        $event = array_merge([
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ], $envelopeExtra);

        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }
}
