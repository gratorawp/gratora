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
 * When the PaymentIntent id never made it onto the donation row.
 *
 * The webhook resolved a donation by gateway_intent_id alone, and a miss
 * answered 200 with handled:false. That 200 is right for an intent that is
 * genuinely not ours -- a 5xx would make Stripe retry it forever -- but it is
 * terminal, so for an intent that IS ours it meant the money was taken against
 * a donation that stayed pending permanently: no receipt, no campaign total,
 * no error anywhere.
 *
 * createIntent stamps the reference into the intent's metadata, and that copy
 * lives on Stripe's side, so it survives whatever stopped the id being written
 * here.
 */
final class StripeWebhookMetadataFallbackTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_live' => $this->secret, 'test_mode' => true],
        ]);

        $account = new StripeAccount(new Crypto());
        $account->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $account->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $account->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

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

    /** A donation whose intent id was never persisted, as the bug produces. */
    private function orphanedDonation(): Donation
    {
        $donor = Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('orphan-' . uniqid() . '@example.test', ['first_name' => 'Orphan', 'last_name' => 'Donor']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'ORPH-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->status            = 'pending';
        $d->gateway           = 'stripe';
        $d->payment_method    = 'card';
        $d->gateway_intent_id = null;
        $d->is_test           = false;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    /** @param array<string,mixed> $object */
    private function postWebhook(string $type, array $object): void
    {
        $payload   = (string) wp_json_encode([
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }

    private function reload(Donation $d): Donation
    {
        return Donation::query()->find('id', (int) $d->id);
    }

    /** @return array<string,mixed> */
    private function succeededIntent(Donation $d, string $intentId, array $metadata): array
    {
        return [
            'id'              => $intentId,
            'status'          => 'succeeded',
            'currency'        => strtolower($d->currency),
            'amount'          => 5000,
            'amount_received' => 5000,
            'livemode'        => true,
            'metadata'        => $metadata,
        ];
    }

    public function test_a_succeeded_intent_is_matched_by_its_reference_metadata(): void
    {
        $donation = $this->orphanedDonation();
        $intentId = 'pi_orphan_' . bin2hex(random_bytes(6));

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent(
            $donation,
            $intentId,
            ['dono_reference' => $donation->reference]
        ));

        $this->assertSame('paid', $this->reload($donation)->status, 'the money is on the donation it paid for');
    }

    /** And the row is healed, so every later event resolves the direct way. */
    public function test_the_intent_id_is_written_back(): void
    {
        $donation = $this->orphanedDonation();
        $intentId = 'pi_orphan_' . bin2hex(random_bytes(6));

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent(
            $donation,
            $intentId,
            ['dono_reference' => $donation->reference]
        ));

        $this->assertSame($intentId, (string) $this->reload($donation)->gateway_intent_id);
    }

    /**
     * The fallback widens what can be matched, so it must not widen what can be
     * paid. A reference in metadata still has to survive every check a directly
     * resolved donation faces.
     */
    public function test_a_mismatched_amount_is_still_refused(): void
    {
        $donation = $this->orphanedDonation();

        $intent = $this->succeededIntent(
            $donation,
            'pi_orphan_' . bin2hex(random_bytes(6)),
            ['dono_reference' => $donation->reference]
        );
        $intent['amount']          = 100;
        $intent['amount_received'] = 100;

        $this->postWebhook('payment_intent.succeeded', $intent);

        $this->assertSame('pending', $this->reload($donation)->status, 'a smaller charge does not settle it');
    }

    public function test_an_intent_with_no_reference_still_goes_unhandled(): void
    {
        $donation = $this->orphanedDonation();

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent(
            $donation,
            'pi_stranger_' . bin2hex(random_bytes(6)),
            []
        ));

        $this->assertSame('pending', $this->reload($donation)->status);
    }

    /** A reference naming somebody else's donation matches nothing of ours. */
    public function test_an_unknown_reference_matches_nothing(): void
    {
        $donation = $this->orphanedDonation();

        $this->postWebhook('payment_intent.succeeded', $this->succeededIntent(
            $donation,
            'pi_stranger_' . bin2hex(random_bytes(6)),
            ['dono_reference' => 'NOT-A-REAL-REFERENCE']
        ));

        $this->assertSame('pending', $this->reload($donation)->status);
    }
}
