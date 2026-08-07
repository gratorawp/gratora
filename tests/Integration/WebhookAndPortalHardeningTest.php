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
    /**
     * Signing up is gated on proof the caller loaded the portal page. Without
     * it these requests are refused before they reach the behaviour under test,
     * and the assertions pass for the wrong reason.
     */
    private function portalToken(): string
    {
        return Plugin::instance()->container
            ->get(\Dono\Donations\AntiSpamGuard::class)
            ->mintPortalToken();
    }

    /**
     * Signing up records a claim; the donor appears when the emailed link comes
     * back. These tests are about what the claim is allowed to write, so they
     * redeem it rather than stopping at the 200.
     *
     * @param array<string,mixed> $body
     */
    private function signUpAndRedeem(array $body): int
    {
        $req = new WP_REST_Request('POST', '/dono/v1/portal/register');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body + ['token' => $this->portalToken()]));
        rest_do_request($req);

        $c     = Plugin::instance()->container;
        $claim = $c->get(\Dono\Donors\PendingSignupRepository::class)->findByEmailHash(
            $c->get(IdentityHasher::class)->emailHash((string) $body['email'])
        );
        if ($claim === null) return 0;

        $raw = $c->get(\Dono\Donors\MagicLinkService::class)->issue(
            0,
            \Dono\Donors\SignupRedemption::PURPOSE,
            (int) $claim->id
        );

        return $c->get(\Dono\Donors\SignupRedemption::class)->redeem($raw);
    }

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

        $this->signUpAndRedeem(['email' => $email, 'first_name' => 'Rude', 'last_name' => 'Word']);

        $fresh = Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get();

        $this->assertNull($fresh->first_name, 'a stranger cannot name someone else');
        $this->assertNull($fresh->last_name);
    }

    public function test_the_donor_export_holds_everything_the_org_export_holds(): void
    {
        // Right of access is to everything held on the donor, and core already
        // has one answer to what that is: the org-side export. The portal built
        // its own thinner bundle, so the same legal obligation had two
        // definitions and the donor got the smaller one.
        $email = 'export-' . uniqid() . '@example.test';
        $donor = Plugin::instance()->container->get(DonorService::class)->findOrCreate($email, [
            'first_name' => 'Ada',
            'country'    => 'GB',
        ]);

        $canonical = Plugin::instance()->container
            ->get(\Dono\Donors\DonorMetricsService::class)
            ->exportData((int) $donor->id);

        $sid = bin2hex(random_bytes(32));
        set_transient('dono_portal_' . hash('sha256', $sid), ['donor_id' => (int) $donor->id, 'csrf' => 'tok'], 3600);
        $_COOKIE['dono_donor_session'] = $sid;

        // The bundle is streamed from a rest_pre_serve_request filter, which
        // only fires when the server actually serves. rest_do_request stops
        // short of that, so the filter is invoked here the way the server would.
        $req = new WP_REST_Request('POST', '/dono/v1/portal/data-export');
        $req->set_header('X-Dono-Csrf', 'tok');
        $res = rest_do_request($req);

        ob_start();
        apply_filters('rest_pre_serve_request', false, $res, $req, rest_get_server());
        $body = ob_get_clean();

        unset($_COOKIE['dono_donor_session']);

        $bundle = json_decode((string) $body, true);
        $this->assertIsArray($bundle, 'the export streams a JSON body');

        foreach (array_keys($canonical) as $section) {
            $this->assertArrayHasKey(
                $section,
                $bundle,
                "the donor's own export is missing the {$section} the org export holds"
            );
        }
    }

    public function test_an_unapplied_amount_change_is_not_recorded_as_applied(): void
    {
        // PayPal does not apply a revise until the subscriber approves it, and
        // says so with an approve link. Treating that as success wrote the new
        // amount to the plan while PayPal carried on charging the old one, and
        // nothing later reconciled the two.
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('approve-' . uniqid() . '@example.test');

        $plan = \Dono\Recurring\RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'needsapproval';
        $plan->gateway_subscription_id = 'I-' . strtoupper(bin2hex(random_bytes(4)));
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = gmdate('Y-m-d H:i:s');
        $plan->created_at              = gmdate('Y-m-d H:i:s');
        $plan->updated_at              = gmdate('Y-m-d H:i:s');
        $plan->save();

        Plugin::instance()->container->get(\Dono\Gateways\GatewayManager::class)
            ->register(new NeedsApprovalGateway());

        $sid = bin2hex(random_bytes(32));
        set_transient('dono_portal_' . hash('sha256', $sid), ['donor_id' => (int) $donor->id, 'csrf' => 'tok'], 3600);
        $_COOKIE['dono_donor_session'] = $sid;

        $req = new WP_REST_Request('POST', '/dono/v1/portal/recurring/' . (int) $plan->id . '/action');
        $req->set_header('content-type', 'application/json');
        $req->set_header('X-Dono-Csrf', 'tok');
        $req->set_body((string) wp_json_encode(['action' => 'change_amount', 'amount_cents' => 5000]));
        $res = rest_do_request($req);

        unset($_COOKIE['dono_donor_session']);

        $this->assertSame(409, $res->get_status(), 'the donor is told it is waiting on them');

        $fresh = \Dono\Recurring\RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(
            2500,
            (int) $fresh->amount_cents,
            'the plan still says what the card is actually being charged'
        );
    }

    public function test_register_still_names_a_donor_it_creates(): void
    {
        $email = 'brand-new-' . uniqid() . '@example.test';

        $this->signUpAndRedeem(['email' => $email, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $fresh = Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get();

        $this->assertNotNull($fresh, 'the donor is created');
        $this->assertSame('Ada', (string) $fresh->first_name, 'and keeps the name they gave for themselves');
        $this->assertSame('Lovelace', (string) $fresh->last_name);
    }

    /**
     * Where a name divides is the donor's to say, not ours to guess: no rule
     * about spaces gets "Mary Jane" and "van der Meer" both right. The two
     * parts are stored exactly as they were typed.
     */
    public function test_register_stores_each_name_part_as_given(): void
    {
        $email = 'compound-' . uniqid() . '@example.test';

        $this->signUpAndRedeem(['email' => $email, 'first_name' => 'Mary Jane', 'last_name' => 'van der Meer']);

        $fresh = Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get();

        $this->assertSame('Mary Jane', (string) $fresh->first_name);
        $this->assertSame('van der Meer', (string) $fresh->last_name);
    }

    /** Going by one name must not block the signup. */
    public function test_register_accepts_a_donor_with_no_surname(): void
    {
        $email = 'mononym-' . uniqid() . '@example.test';

        $this->signUpAndRedeem(['email' => $email, 'first_name' => 'Prince']);

        $fresh = Donor::query()
            ->where('email_hash', Plugin::instance()->container->get(IdentityHasher::class)->emailHash($email))
            ->get();

        $this->assertNotNull($fresh, 'the donor is created');
        $this->assertSame('Prince', (string) $fresh->first_name);
        $this->assertNull($fresh->last_name);
    }
}

/** Answers a revise the way PayPal does when the subscriber must approve it. */
final class NeedsApprovalGateway implements \Dono\Gateways\PaymentGateway, \Dono\Gateways\SubscriptionAware
{
    public function id(): string { return 'needsapproval'; }
    public function label(): string { return 'Needs approval'; }
    public function description(): string { return ''; }
    public function frequencies(): array { return ['monthly']; }
    public function paymentMethods(): array { return []; }
    public function countries(): array { return []; }
    public function currencies(): array { return ['USD']; }
    public function canCharge(): bool { return true; }

    public function createIntent(\Dono\Donations\Donation $donation): \Dono\Gateways\GatewayIntentResult
    {
        return new \Dono\Gateways\GatewayIntentResult(ok: false, error: 'not used');
    }

    public function confirm(\Dono\Donations\Donation $donation, array $payload = []): \Dono\Gateways\GatewayConfirmResult
    {
        return new \Dono\Gateways\GatewayConfirmResult(ok: false, error: 'not used');
    }

    public function handleWebhook(WP_REST_Request $request): \Dono\Gateways\WebhookOutcome
    {
        return new \Dono\Gateways\WebhookOutcome(signature_ok: false, external_id: '', event_type: '', handled: false);
    }

    public function refund(\Dono\Donations\Donation $donation, int $amountCents, ?string $reason = null): \Dono\Gateways\RefundResult
    {
        return new \Dono\Gateways\RefundResult(ok: false, error: 'not used');
    }

    public function cancelSubscription(\Dono\Recurring\RecurringPlan $plan, ?string $reason = null): void {}
    public function pauseSubscription(\Dono\Recurring\RecurringPlan $plan, ?string $resumesAt = null): void {}
    public function resumeSubscription(\Dono\Recurring\RecurringPlan $plan): void {}

    public function updateSubscriptionAmount(\Dono\Recurring\RecurringPlan $plan, int $amountCents): void
    {
        throw new \Dono\Gateways\SubscriptionChangeNeedsApproval(
            'needs approval',
            'https://www.paypal.com/approve/xyz'
        );
    }
}
