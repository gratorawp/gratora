<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\StripeConnectAccount;
use Dono\Recurring\RecurringPlan;
use WP_REST_Request;

/**
 * When a donor cancels a recurring plan through the donor portal, the same
 * `dono.recurring.cancelled` event the Stripe webhook fires must also fire -
 * otherwise the `subscription_cancelled` email stays silent on donor-initiated
 * cancels.
 */
final class PortalRecurringCancelTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stripe gateway must exist so the portal's gateway lookup doesn't no-op.
        update_option('dono_gateway_config', ['test_mode' => true]);
        Plugin::instance()->container->get(StripeConnectAccount::class)->store(
            [
                'stripe_user_id'           => 'acct_test_portal',
                'stripe_access_token_test' => 'sk_test_portal',
                'stripe_access_token'      => 'sk_live_portal',
            ],
            ['charges_enabled' => true],
        );

        // Intercept Stripe DELETE /v1/subscriptions/{id} so we don't hit the real API.
        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (! is_string($url) || ! str_starts_with($url, 'https://api.stripe.com/')) return $pre;
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode(['id' => 'sub_test', 'status' => 'canceled']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3);

        $c       = Plugin::instance()->container;
        $manager = $c->get(\Dono\Gateways\GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Dono\Gateways\Stripe\StripeGateway(
                $c->get(\Dono\Gateways\Stripe\StripeApi::class),
                $c->get(\Dono\Donations\DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(\Dono\Gateways\Stripe\StripeConnectAccount::class),
                $c->get(\Dono\Donors\DonorRepository::class),
                $c->get(\Dono\Donors\DonorService::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            ));
        }
    }

    public function test_donor_initiated_cancel_fires_canonical_event_and_sends_email(): void
    {
        $donor = Plugin::instance()->container
            ->get(\Dono\Donors\DonorService::class)
            ->findOrCreate('cancel-tester@example.com', ['first_name' => 'Cancel', 'last_name' => 'Tester']);

        $plan = $this->seedPlan((int) $donor->id);
        $csrf = $this->openPortalFor((int) $donor->id);

        $mails        = $this->captureMails();
        $eventFired   = false;
        $reasonFromEvent = null;
        add_action('dono.recurring.cancelled', function ($plan, $reason) use (&$eventFired, &$reasonFromEvent): void {
            $eventFired = true;
            $reasonFromEvent = $reason;
        }, 10, 2);

        try {
            $req = new WP_REST_Request('POST', "/dono/v1/portal/recurring/{$plan->id}/action");
            $req->set_header('content-type', 'application/json');
            $req->set_header('X-Dono-Csrf', $csrf);
            $req->set_body((string) wp_json_encode(['action' => 'cancel', 'reason' => 'too expensive']));
            $res = rest_do_request($req);
            $this->assertSame(200, $res->get_status(), 'cancel succeeds: ' . wp_json_encode($res->get_data()));

            $this->assertTrue($eventFired, 'dono.recurring.cancelled fires from the donor portal');
            $this->assertSame('too expensive', $reasonFromEvent);

            $cancellationMail = $this->findMailBySubject($mails, 'cancelled');
            $this->assertNotNull($cancellationMail, 'subscription_cancelled email goes out on donor cancel');

            $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
            $this->assertSame('cancelled', $fresh->status);
            $this->assertSame('too expensive', $fresh->cancellation_reason);
        } finally {
            unset($_COOKIE['dono_donor_session']);
        }
    }

    private function seedPlan(int $donorId): RecurringPlan
    {
        $plan = RecurringPlan::make();
        $plan->donor_id           = $donorId;
        $plan->gateway            = 'stripe';
        $plan->gateway_subscription_id = 'sub_test_' . bin2hex(random_bytes(3));
        $plan->gateway_customer_id     = 'cus_test_portal';
        $plan->amount_cents       = 2500;
        $plan->currency           = 'USD';
        $plan->interval_unit      = 'month';
        $plan->interval_count     = 1;
        $plan->status             = 'active';
        $plan->started_at         = '2026-01-01 00:00:00';
        $plan->next_payment_at    = '2026-06-01 00:00:00';
        $plan->payments_count     = 3;
        $plan->total_paid_cents   = 7500;
        $plan->created_at         = '2026-01-01 00:00:00';
        $plan->updated_at         = '2026-01-01 00:00:00';
        $plan->save();
        return $plan;
    }

    private function openPortalFor(int $donorId): string
    {
        $sid  = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        set_transient(
            'dono_portal_' . hash('sha256', $sid),
            ['donor_id' => $donorId, 'csrf' => $csrf],
            HOUR_IN_SECONDS
        );
        $_COOKIE['dono_donor_session'] = $sid;
        return $csrf;
    }

    private function findMailBySubject(\ArrayObject $mails, string $needle): ?array
    {
        foreach ($mails as $m) {
            if (stripos((string) ($m['subject'] ?? ''), $needle) !== false) return $m;
        }
        return null;
    }
}
