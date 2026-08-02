<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Foundation\Crypto\Crypto;
use Dono\Gateways\Stripe\StripeAccount;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * Drives `invoice.payment_succeeded`, `invoice.payment_failed`, and
 * `customer.subscription.deleted` webhooks against a seeded RecurringPlan
 * and asserts the expected local state + events.
 */
final class StripeSubscriptionRenewalTest extends IntegrationTestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'whsec_test_' . bin2hex(random_bytes(8));
        update_option('dono_gateway_config', [
            'stripe' => ['webhook_secret_test' => $this->secret, 'test_mode' => true],
        ]);

        // Stripe gateway only registers when a connected account is present.
        $stripeAcct = (new StripeAccount(new Crypto()));
        $stripeAcct->saveKeys(true, 'sk_test_connected', 'pk_test_seed');
        $stripeAcct->saveKeys(false, 'sk_live_connected', 'pk_live_seed');
        $stripeAcct->refresh(['id' => 'acct_test_123', 'charges_enabled' => true]);

        // CoreModule registers the Stripe gateway only when the connected
        // account is present at boot. Tests connect the account in setUp(), so
        // we re-register it manually here.
        $c = \Dono\Foundation\Plugin::instance()->container;
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

    public function test_invoice_payment_succeeded_creates_renewal_donation(): void
    {
        $plan  = $this->seedPlan();
        $mails = $this->captureMails();

        $renewedFired = false;
        add_action('dono.recurring.renewed', function () use (&$renewedFired): void {
            $renewedFired = true;
        });

        $invoice = $this->buildInvoice($plan, 2500, 'subscription_cycle');
        $this->postWebhook('invoice.payment_succeeded', $invoice);

        $renewal = Donation::query()
            ->where('recurring_plan_id', $plan->id)
            ->orderBy('id', 'DESC')
            ->get();

        $this->assertNotNull($renewal, 'Renewal donation was created');
        $this->assertSame('paid', $renewal->status);
        $this->assertSame(2500, (int) $renewal->amount_cents);
        $this->assertSame('monthly', $renewal->frequency);
        $this->assertTrue($renewedFired, 'dono.recurring.renewed action fired');

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(2, (int) $fresh->payments_count, 'plan payments_count incremented');
        $this->assertSame(2500 + (int) $plan->amount_cents, (int) $fresh->total_paid_cents);

        // The `recurring_renewal` template must fire so donors see their plan
        // renewed (default subject: "Your recurring donation renewed").
        $renewalMail = $this->findMailBySubject($mails, 'renewed');
        $this->assertNotNull($renewalMail, 'recurring_renewal email goes out');
    }

    public function test_renewal_inherits_the_plan_mode_not_the_current_setting(): void
    {
        // Plan is flagged test; the global mode resolves live (no top-level
        // test_mode set in setUp), so the only way the renewal can come out
        // test is by inheriting the plan's own mode.
        $plan = $this->seedPlan();
        $plan->is_test = true;
        $plan->save();

        $invoice = $this->buildInvoice($plan, 2500, 'subscription_cycle');
        $this->postWebhook('invoice.payment_succeeded', $invoice);

        $renewal = Donation::query()
            ->where('recurring_plan_id', $plan->id)
            ->orderBy('id', 'DESC')
            ->get();

        $this->assertNotNull($renewal);
        $this->assertTrue((bool) $renewal->is_test, 'Renewal inherits the plan test mode, not the live setting');
    }

    public function test_invoice_payment_succeeded_is_idempotent(): void
    {
        $plan = $this->seedPlan();
        $invoice = $this->buildInvoice($plan, 2500, 'subscription_cycle');

        $this->postWebhook('invoice.payment_succeeded', $invoice);
        $this->postWebhook('invoice.payment_succeeded', $invoice);

        $count = Donation::query()->where('recurring_plan_id', $plan->id)->count();
        $this->assertSame(1, (int) $count, 'Second delivery does not duplicate the donation');

        // ...and the plan counters reflect exactly ONE renewal on top of the
        // seeded baseline (1 / 2500), not two: a redelivered webhook must not
        // inflate payments_count / total_paid_cents (would be 3 / 7500 if it did).
        $fresh = \Dono\Recurring\RecurringPlan::query()->where('id', $plan->id)->get();
        $this->assertSame(2, (int) $fresh->payments_count, 'redelivery does not double-count payments_count');
        $this->assertSame(5000, (int) $fresh->total_paid_cents, 'redelivery does not double-count total_paid_cents');
    }

    public function test_first_subscription_invoice_is_ignored(): void
    {
        $plan = $this->seedPlan();

        $invoice = $this->buildInvoice($plan, 2500, 'subscription_create');
        $this->postWebhook('invoice.payment_succeeded', $invoice);

        $count = Donation::query()->where('recurring_plan_id', $plan->id)->count();
        $this->assertSame(0, (int) $count, 'subscription_create invoice (paid by PI) does not double-charge');
    }

    public function test_invoice_payment_failed_increments_failed_renewals(): void
    {
        $plan = $this->seedPlan();
        $invoice = $this->buildInvoice($plan, 2500, 'subscription_cycle');

        $this->postWebhook('invoice.payment_failed', $invoice);

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(1, (int) $fresh->failed_renewals_count);
        $this->assertSame('active', $fresh->status, 'Single failure does not cancel');
    }

    /**
     * A failed renewal is the start of losing the gift, so it has to be
     * announceable. Without this signal nothing downstream can tell anyone.
     */
    public function test_invoice_payment_failed_fires_the_renewal_failed_action(): void
    {
        $plan = $this->seedPlan();

        $seen = [];
        add_action('dono.recurring.renewal_failed', function ($p, $ctx) use (&$seen): void {
            $seen[] = ['plan_id' => (int) $p->id, 'ctx' => $ctx];
        }, 10, 2);

        $this->postWebhook('invoice.payment_failed', $this->buildInvoice($plan, 2500, 'subscription_cycle'));

        $this->assertCount(1, $seen);
        $this->assertSame((int) $plan->id, $seen[0]['plan_id']);
        $this->assertSame('stripe', $seen[0]['ctx']['gateway']);
        $this->assertSame(1, $seen[0]['ctx']['attempt'], 'so a listener can tell a first failure from a fourth');
    }

    public function test_a_failed_renewal_tells_the_donor(): void
    {
        $plan  = $this->seedPlan();
        $mails = $this->captureMails();

        $this->postWebhook('invoice.payment_failed', $this->buildInvoice($plan, 2500, 'subscription_cycle'));

        $mail = $this->findMailBySubject($mails, "couldn't be taken");
        $this->assertNotNull(
            $mail,
            'the donor is told their payment failed, not left to find out when the plan is cancelled'
        );
        $this->assertStringNotContainsString(
            '{portal_url}',
            (string) $mail['message'],
            'every tag the template uses must be supplied, or the donor reads the placeholder'
        );
        $this->assertStringContainsString('donor-portal', (string) $mail['message'], 'and it points somewhere real');
    }

    /**
     * Stripe retries a failed invoice on its own schedule. One notice per
     * failing card is help; four is the charity nagging a donor who already
     * knows, so only the first attempt mails.
     */
    public function test_a_retry_does_not_mail_the_donor_again(): void
    {
        $plan = $this->seedPlan();
        $this->postWebhook('invoice.payment_failed', $this->buildInvoice($plan, 2500, 'subscription_cycle'));

        $mails = $this->captureMails();
        $this->postWebhook('invoice.payment_failed', $this->buildInvoice($plan, 2500, 'subscription_cycle'));

        $this->assertNull($this->findMailBySubject($mails, "couldn't be taken"));
        $this->assertSame(
            2,
            (int) \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id)->failed_renewals_count,
            'the second failure is still counted, it just does not re-mail'
        );
    }

    public function test_a_failed_renewal_is_recorded_for_reporting(): void
    {
        $plan = $this->seedPlan();

        $this->postWebhook('invoice.payment_failed', $this->buildInvoice($plan, 2500, 'subscription_cycle'));

        $this->assertSame(
            1,
            (int) \Dono\Analytics\Event::query()->where('type', 'recurring.failed')->count(),
            'the cancellation path records one, so the failure that precedes it must too'
        );
    }

    public function test_subscription_deleted_marks_plan_cancelled_and_fires_event(): void
    {
        $plan = $this->seedPlan();
        $mails = $this->captureMails();

        $cancelFired = false;
        add_action('dono.recurring.cancelled', function () use (&$cancelFired): void {
            $cancelFired = true;
        });

        $this->postWebhook('customer.subscription.deleted', [
            'id'   => $plan->gateway_subscription_id,
            'cancellation_details' => ['reason' => 'requested_by_customer'],
        ]);

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('requested_by_customer', $fresh->cancellation_reason);
        $this->assertTrue($cancelFired);

        $cancellationMail = $this->findMailBySubject($mails, 'cancelled');
        $this->assertNotNull($cancellationMail, 'subscription_cancelled email goes out');
    }

    public function test_a_test_signed_event_cannot_renew_a_live_plan(): void
    {
        $plan = $this->seedPlan();
        $plan->is_test = false;
        $plan->save();

        $before = (int) $plan->payments_count;

        // Signed with the test webhook secret, which is a far softer credential
        // than the live one: staging env files, CI, contractors. Nothing in the
        // event body decides the mode, so claiming livemode changes nothing.
        $this->postWebhook('invoice.payment_succeeded', $this->buildInvoice($plan, 500000, 'subscription_cycle'));

        $renewal = Donation::query()->where('recurring_plan_id', $plan->id)->orderBy('id', 'DESC')->get();
        $this->assertNull($renewal, 'no live money is banked on a test-mode signature');

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame($before, (int) $fresh->payments_count, 'and the live plan counters do not move');
    }

    public function test_a_test_signed_event_cannot_cancel_a_live_plan(): void
    {
        $plan = $this->seedPlan();
        $plan->is_test = false;
        $plan->save();

        $this->postWebhook('customer.subscription.deleted', [
            'id'     => (string) $plan->gateway_subscription_id,
            'status' => 'canceled',
        ]);

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame('active', (string) $fresh->status, 'a test secret cannot stop a live donor\'s giving');
    }

    public function test_a_test_signed_failure_cannot_touch_a_live_plan(): void
    {
        $plan = $this->seedPlan();
        $plan->is_test = false;
        $plan->save();

        $before = (int) $plan->failed_renewals_count;

        $this->postWebhook('invoice.payment_failed', $this->buildInvoice($plan, 2500, 'subscription_cycle'));

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame($before, (int) $fresh->failed_renewals_count);
    }

    private function seedPlan(): RecurringPlan
    {
        // Use the service so the email is properly hashed + encrypted; the
        // cancellation email template needs decryptEmail to round-trip.
        $donorService = \Dono\Foundation\Plugin::instance()->container
            ->get(\Dono\Donors\DonorService::class);
        $donor = $donorService->findOrCreate('renewer@example.com', [
            'first_name' => 'Recurring',
            'last_name'  => 'Renewer',
        ]);

        $plan = RecurringPlan::make();
        $plan->donor_id           = (int) $donor->id;
        $plan->gateway            = 'stripe';
        // The harness signs with the test webhook secret, so the plan it acts
        // on is a test plan. Left live, the fixture was a test-mode secret
        // renewing live money, which is the thing the mode guard refuses.
        $plan->is_test            = true;
        $plan->gateway_subscription_id = 'sub_test_renew_' . bin2hex(random_bytes(4));
        $plan->gateway_customer_id     = 'cus_test_renew';
        $plan->amount_cents       = 2500;
        $plan->currency           = 'USD';
        $plan->interval_unit      = 'month';
        $plan->interval_count     = 1;
        $plan->status             = 'active';
        $plan->started_at         = '2026-01-01 00:00:00';
        $plan->next_payment_at    = '2026-02-01 00:00:00';
        $plan->payments_count     = 1;
        $plan->total_paid_cents   = 2500;
        $plan->created_at         = '2026-01-01 00:00:00';
        $plan->updated_at         = '2026-01-01 00:00:00';
        $plan->save();
        return $plan;
    }

    /**
     * @param string $billingReason 'subscription_cycle' (renewal) or 'subscription_create' (first charge)
     */
    private function buildInvoice(RecurringPlan $plan, int $amountCents, string $billingReason): array
    {
        $piId = 'pi_test_inv_' . bin2hex(random_bytes(4));
        return [
            'id'             => 'in_test_' . bin2hex(random_bytes(4)),
            'subscription'   => $plan->gateway_subscription_id,
            'billing_reason' => $billingReason,
            'payment_intent' => $piId,
            'amount_paid'    => $amountCents,
            'currency'       => strtolower($plan->currency),
            'period_start'   => 1738368000,
            'period_end'     => 1740787200,
            'lines'          => [
                'data' => [
                    ['period' => ['start' => 1738368000, 'end' => 1740787200]],
                ],
            ],
        ];
    }

    private function postWebhook(string $type, array $object): void
    {
        $event = [
            'id'   => 'evt_' . bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ];
        $payload   = (string) wp_json_encode($event);
        $timestamp = (string) time();
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);
        $sigHeader = "t={$timestamp},v1={$sig}";

        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/stripe');
        $req->set_header('content-type', 'application/json');
        $req->set_header('stripe_signature', $sigHeader);
        $req->set_body($payload);
        rest_do_request($req);
    }

    private function findMailBySubject(\ArrayObject $mails, string $needle): ?array
    {
        foreach ($mails as $m) {
            if (stripos((string) ($m['subject'] ?? ''), $needle) !== false) return $m;
        }
        return null;
    }
}
