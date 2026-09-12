<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Gateways\CloseUnsettledResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeGateway;
use Gratora\Foundation\Plugin;
use WP_Error;

/**
 * Asking Stripe to close the payment behind a spam attempt.
 *
 * The answer decides whether an admin may take the row off their list, so the
 * expensive mistake is optimism: a row reported closed on a guess is a card
 * that can still be charged against a donation nobody is watching any more.
 */
final class StripeCloseUnsettledTest extends IntegrationTestCase
{
    /** Status the stubbed GET /payment_intents/{id} reports. */
    private string $intentStatus = 'requires_payment_method';

    /** Set to fail the transport rather than answer. */
    private bool $unreachable = false;

    /** Set to answer every call with an error Stripe itself would send. */
    private bool $refuses = false;

    /** @var list<string> "METHOD path" the stub was asked for, in order. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('gratora_gateway_config', [
            'test_mode' => true,
            'stripe'    => ['webhook_secret_test' => 'whsec_close'],
        ]);

        $c    = Plugin::instance()->container;
        $acct = $c->get(StripeAccount::class);
        $acct->saveKeys(true, 'sk_test_close', 'pk_test_seed');
        $acct->refresh(['id' => 'acct_close', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new StripeGateway(
                $c->get(\Gratora\Gateways\Stripe\StripeApi::class),
                $c->get(\Gratora\Donations\DonationRepository::class),
                $c->get(\Gratora\Donations\DonationService::class),
                $acct,
                $c->get(\Gratora\Donors\DonorRepository::class),
                $c->get(\Gratora\Donors\DonorService::class),
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
            ));
        }

        $this->stubStripe();
    }

    private function gateway(): StripeGateway
    {
        $gateway = Plugin::instance()->container->get(GatewayManager::class)->get('stripe');
        $this->assertInstanceOf(StripeGateway::class, $gateway);

        return $gateway;
    }

    private function donation(string $intentId = 'pi_spam_1'): Donation
    {
        $d                    = Donation::make();
        $d->reference         = 'CLOSE-' . uniqid();
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'pending';
        $d->gateway           = 'stripe';
        $d->gateway_intent_id = $intentId;
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = true;
        $d->save();

        return $d;
    }

    private function cancelled(): bool
    {
        foreach ($this->calls as $call) {
            if (str_contains($call, '/cancel')) return true;
        }

        return false;
    }

    public function test_an_abandoned_intent_is_cancelled_and_reported_closed(): void
    {
        $this->intentStatus = 'requires_payment_method';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertTrue($result->isClosed());
        $this->assertTrue($this->cancelled(), 'the intent was actually cancelled, not just read');
    }

    public function test_an_already_cancelled_intent_needs_no_second_cancel(): void
    {
        $this->intentStatus = 'canceled';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertTrue($result->isClosed());
        $this->assertFalse($this->cancelled());
    }

    /**
     * The card tester whose charge went through between the list rendering and
     * the admin clicking. Trashing here would hide money that arrived.
     */
    public function test_a_succeeded_intent_refuses_and_is_never_cancelled(): void
    {
        $this->intentStatus = 'succeeded';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertSame(CloseUnsettledResult::MONEY_MAY_ARRIVE, $result->outcome);
        $this->assertFalse($result->isClosed());
        $this->assertFalse($this->cancelled(), 'a settled payment is never cancelled');
        $this->assertStringContainsString('succeeded', (string) $result->reason);
    }

    public function test_a_processing_intent_refuses_because_the_debit_can_still_settle(): void
    {
        $this->intentStatus = 'processing';

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertSame(CloseUnsettledResult::MONEY_MAY_ARRIVE, $result->outcome);
        $this->assertFalse($this->cancelled());
    }

    public function test_an_unreachable_stripe_is_not_read_as_closed(): void
    {
        $this->unreachable = true;

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertSame(CloseUnsettledResult::UNREACHABLE, $result->outcome);
        $this->assertFalse($result->isClosed());

        // The one outcome the age rule may still admit: nothing was learned
        // either way, as opposed to being told no.
        $this->assertTrue($result->mayFallBackOnAge());
    }

    public function test_a_refusal_is_an_answer_and_does_not_fall_back_on_age(): void
    {
        $this->refuses = true;

        $result = $this->gateway()->closeUnsettled($this->donation());

        $this->assertSame(CloseUnsettledResult::REFUSED, $result->outcome);
        $this->assertFalse(
            $result->mayFallBackOnAge(),
            'waiting thirty days does not make a refusal any less true'
        );
    }

    public function test_a_row_that_never_reached_stripe_is_closed_without_asking(): void
    {
        $result = $this->gateway()->closeUnsettled($this->donation(''));

        $this->assertTrue($result->isClosed());
        $this->assertSame([], $this->calls, 'nothing payable was created, so nothing was asked');
    }

    private function stubStripe(): void
    {
        $self = $this;
        add_filter('pre_http_request', static function ($pre, $args, $url) use ($self) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) {
                return $pre;
            }

            $path   = (string) (parse_url($url)['path'] ?? '');
            $method = strtoupper((string) ($args['method'] ?? 'POST'));
            $self->calls[] = $method . ' ' . $path;

            if ($self->unreachable) {
                return new WP_Error('http_request_failed', 'cURL error 28: connection timed out');
            }

            if ($self->refuses) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode([
                        'error' => ['code' => 'intent_invalid_state', 'message' => 'This PaymentIntent cannot be cancelled.'],
                    ]),
                    'response' => ['code' => 400, 'message' => 'Bad Request'],
                    'cookies'  => [], 'filename' => null,
                ];
            }

            // A cancel that Stripe accepts answers with the cancelled intent.
            $status = str_contains($path, '/cancel') ? 'canceled' : $self->intentStatus;

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => 'pi_spam_1', 'status' => $status]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }
}
