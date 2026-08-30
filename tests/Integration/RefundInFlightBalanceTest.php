<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donations\DonationRepository;
use FundKit\Donations\DonationService;
use FundKit\Donations\Refund;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Time\Clock;
use FundKit\Gateways\GatewayConfirmResult;
use FundKit\Gateways\GatewayIntentResult;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\PaymentGateway;
use FundKit\Gateways\RefundResult;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Gateways\Stripe\StripeApi;
use FundKit\Gateways\Stripe\StripeGateway;
use FundKit\Gateways\WebhookOutcome;
use FundKit\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Money the gateway has accepted and not settled is spent.
 *
 * A PayPal eCheck refund answers PENDING and a Stripe bank refund is created
 * pending: the org no longer decides where that money goes, but the donor does
 * not have it yet. Counted as neither refunded nor refundable, the refund
 * dialog offers the whole donation again and the second refund pays the donor
 * twice for one donation.
 *
 * Driven through the registered admin route and the registered webhook route,
 * because that is the door the screen and the gateway actually use.
 */
final class RefundInFlightBalanceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Snapshots the registry so tearDown puts it back without the probe.
        $this->deregisterGateway('fundkit_no_such_gateway');

        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if (! $manager->get('echeckprobe')) {
            $manager->register(new AwaitedRefundGateway());
        }
    }

    public function test_a_refund_awaiting_settlement_is_off_the_refundable_balance(): void
    {
        $donation = $this->paidDonation('echeckprobe', 10000);

        $issued = $this->refund($donation, 6000);
        $this->assertSame(200, $issued->get_status());
        $this->assertSame('pending', $issued->get_data()['refund']['status'] ?? '');

        $shown = $this->show($donation)['donation'];

        $this->assertSame(0, (int) $shown['refunded_cents'], 'the donor has not been repaid, so nothing is refunded');
        $this->assertSame(6000, (int) $shown['refund_pending_cents'], 'but it is stated, not silent');
        $this->assertSame(4000, (int) $shown['refundable_cents'], 'and it is not offered a second time');
    }

    /**
     * The screen's own cap is derived from the server, so the refusal is what
     * stands between a redrawn dialog and a second real refund.
     */
    public function test_the_server_refuses_to_send_money_that_is_already_on_its_way(): void
    {
        $donation = $this->paidDonation('echeckprobe', 10000);

        $this->assertSame(200, $this->refund($donation, 6000)->get_status());

        AwaitedRefundGateway::$calls = 0;
        $second = $this->refund($donation, 6000);

        $this->assertSame(422, $second->get_status());
        $this->assertSame(0, AwaitedRefundGateway::$calls, 'the gateway is never asked to move the money');
        $this->assertSame(
            1,
            (int) Refund::query()->where('donation_id', (int) $donation->id)->count(),
            'and no second refund is recorded'
        );

        $message = (string) ($second->get_data()['message'] ?? '');
        $this->assertStringContainsString('$60.00', $message, 'the operator is told what is holding the balance');
        $this->assertStringContainsString('$40.00', $message, 'and what is left');
    }

    /** The remainder is still refundable, so a partial in-flight refund blocks nothing else. */
    public function test_what_is_left_over_can_still_be_refunded(): void
    {
        $donation = $this->paidDonation('echeckprobe', 10000);

        $this->assertSame(200, $this->refund($donation, 6000)->get_status());
        $this->assertSame(200, $this->refund($donation, 4000)->get_status());

        $shown = $this->show($donation)['donation'];
        $this->assertSame(10000, (int) $shown['refund_pending_cents']);
        $this->assertSame(0, (int) $shown['refundable_cents']);
    }

    /**
     * The other end of the same hold. A bank refund Stripe gives up on returns
     * the money to the org, so the awaited row stops reserving the balance or
     * the donation could never be refunded by any route again.
     */
    public function test_a_refund_the_gateway_gave_up_on_gives_the_balance_back(): void
    {
        $this->registerStripe();
        $refundId = 're_' . bin2hex(random_bytes(4));
        $this->stubStripeRefund($refundId, 5000, 'pending');

        $donation = $this->paidDonation('stripe', 5000);
        $this->assertSame(200, $this->refund($donation, 5000)->get_status());

        $this->assertSame(0, (int) $this->show($donation)['donation']['refundable_cents']);

        $this->postStripeWebhook('refund.failed', [
            'id'             => $refundId,
            'payment_intent' => (string) $donation->gateway_intent_id,
            'amount'         => 5000,
            'status'         => 'failed',
        ]);

        $shown = $this->show($donation)['donation'];
        $this->assertSame(0, (int) $shown['refund_pending_cents'], 'nothing is in flight any more');
        $this->assertSame(5000, (int) $shown['refundable_cents'], 'so the donor can be repaid another way');
        $this->assertSame(
            'failed',
            (string) Refund::query()->where('gateway_refund_id', $refundId)->get()->status,
            'and the record says what became of it'
        );
    }

    /**
     * Only Stripe tells us a refund was abandoned. On every other gateway the
     * hold would stand for good, and a donation nobody can refund is a worse
     * place to leave an operator than the double payment the hold prevents.
     */
    public function test_an_operator_can_let_go_of_a_refund_that_never_settled(): void
    {
        $donation = $this->paidDonation('echeckprobe', 10000);
        $refundId = (string) ($this->refund($donation, 6000)->get_data()['refund']['gateway_refund_id'] ?? '');
        $this->assertNotSame('', $refundId, 'the fixture needs a refund to release');

        $res = $this->releaseRefund($donation, $refundId);

        $this->assertSame(200, $res->get_status());
        $shown = $this->show($donation)['donation'];
        $this->assertSame(0, (int) $shown['refund_pending_cents'], 'the hold is still standing');
        $this->assertSame(10000, (int) $shown['refundable_cents'], 'the donation is refundable again');
    }

    /** Releasing something that is not waiting must not quietly report success. */
    public function test_releasing_a_refund_that_is_not_waiting_is_refused(): void
    {
        $donation = $this->paidDonation('echeckprobe', 10000);

        $res = $this->releaseRefund($donation, 'no-such-refund');

        $this->assertSame(422, $res->get_status());
    }

    /**
     * Letting go says the money never moved. If the gateway then settles it
     * after all, that is the money genuinely leaving and it has to be booked.
     */
    public function test_a_settlement_after_a_release_is_still_recorded(): void
    {
        $donation = $this->paidDonation('echeckprobe', 10000);
        $refundId = (string) ($this->refund($donation, 6000)->get_data()['refund']['gateway_refund_id'] ?? '');
        $this->releaseRefund($donation, $refundId);

        $this->donationService()->recordExternalRefund($donation, 6000, $refundId, 'settled late', 'gateway', [], true);

        $shown = $this->show($donation)['donation'];
        $this->assertSame(6000, (int) $shown['refunded_cents'], 'a late settlement was dropped as a duplicate');
    }

    private function donationService(): DonationService
    {
        return \FundKit\Foundation\Plugin::instance()->container->get(DonationService::class);
    }

    private function releaseRefund(Donation $donation, string $refundId): \WP_REST_Response
    {
        $req = new \WP_REST_Request('POST', "/fundkit/v1/admin/donations/{$donation->reference}/release-refund");
        $req->set_body_params(['gateway_refund_id' => $refundId]);

        return rest_do_request($req);
    }

    private function paidDonation(string $gateway, int $cents): Donation
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('inflight-' . uniqid() . '@example.test', ['first_name' => 'In', 'last_name' => 'Flight']);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'INFLT-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->currency          = 'USD';
        $d->gateway           = $gateway;
        $d->gateway_intent_id = 'pi_test_' . bin2hex(random_bytes(6));
        $d->gateway_txn_id    = 'txn_test_' . bin2hex(random_bytes(6));
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return $d;
    }

    private function refund(Donation $donation, int $cents): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/donations/' . $donation->reference . '/refund');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['amount_cents' => $cents]));

        return rest_do_request($req);
    }

    /** @return array<string,mixed> */
    private function show(Donation $donation): array
    {
        $req = new WP_REST_Request('GET', '/fundkit/v1/admin/donations/' . $donation->reference);

        return (array) rest_do_request($req)->get_data();
    }

    private string $stripeSecret = '';

    private function registerStripe(): void
    {
        $this->stripeSecret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('fundkit_gateway_config', [
            'stripe' => ['webhook_secret_live' => $this->stripeSecret, 'test_mode' => true],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $account->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $account->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(StripeApi::class),
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(StripeAccount::class),
                $c->get(DonorRepository::class),
                $c->get(DonorService::class),
                $c->get(Clock::class),
                $c->get(RecurringPlanRepository::class),
            ));
        }
    }

    /** Stripe creates a bank refund pending: accepted, unsettled, still able to fail. */
    private function stubStripeRefund(string $refundId, int $minorUnits, string $status): void
    {
        add_filter('pre_http_request', static function ($pre, $args, $url) use ($refundId, $minorUnits, $status) {
            if (! is_string($url) || ! str_contains($url, 'api.stripe.com')) return $pre;

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode([
                    'id'       => $refundId,
                    'object'   => 'refund',
                    'amount'   => $minorUnits,
                    'status'   => $status,
                    'livemode' => true,
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    /** @param array<string,mixed> $object */
    private function postStripeWebhook(string $type, array $object): void
    {
        $payload = (string) wp_json_encode([
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->stripeSecret);

        $req = new WP_REST_Request('POST', '/fundkit/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', "t={$timestamp},v1={$sig}");
        $req->set_body($payload);
        rest_do_request($req);
    }
}

/** Answers the way PayPal answers an eCheck refund: taken on, not settled. */
final class AwaitedRefundGateway implements PaymentGateway
{
    public static int $calls = 0;

    public function id(): string { return 'echeckprobe'; }
    public function label(): string { return 'eCheck Probe'; }
    public function description(): string { return 'Accepts refunds without settling them.'; }
    public function frequencies(): array { return ['one_time']; }
    public function paymentMethods(): array { return ['bank']; }
    public function countries(): array { return ['*']; }
    public function currencies(): array { return ['*']; }
    public function canCharge(): bool { return true; }

    public function createIntent(Donation $donation): GatewayIntentResult
    {
        return new GatewayIntentResult(intent_id: 'echeck_intent_1');
    }

    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(success: true, gateway_txn_id: 'echeck_txn_1');
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported('echeckprobe');
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        self::$calls++;

        return new RefundResult(
            success:           true,
            gateway_refund_id: 're_echeck_' . bin2hex(random_bytes(6)),
            amount_cents:      $amountCents,
            settled:           false,
        );
    }
}
