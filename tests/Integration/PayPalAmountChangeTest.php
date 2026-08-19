<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlanRecorder;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * A PayPal amount change only happens when the donor approves it, and PayPal
 * says so on its own event rather than back through the call that asked.
 *
 * So the local row is not current until that event is read. Left unread it
 * keeps the amount the plan was created with while PayPal collects the new one,
 * and the divergence is not passive: the next thing to send the local amount
 * back to PayPal, which the card update does, revises the donor down to the
 * figure they had already changed.
 */
final class PayPalAmountChangeTest extends IntegrationTestCase
{
    /** @var array<int,array{url:string,body:string}> */
    private array $calls = [];

    private string $subscriptionPlanId = 'P-OLD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        update_option('dono_gateway_config', ['test_mode' => true]);

        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

        $manager = Plugin::instance()->container->get(\Dono\Gateways\GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register($this->gateway());
        }

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $this->calls[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (str_contains($path, '/v1/oauth2/token'))          return $this->reply(['access_token' => 'A21', 'expires_in' => 32400]);
            if (str_contains($path, '/verify-webhook-signature')) return $this->reply(['verification_status' => 'SUCCESS']);
            if (str_contains($path, '/v1/billing/subscriptions/')) {
                return $this->reply([
                    'id'      => 'I-AMT-1',
                    'status'  => 'ACTIVE',
                    // What PayPal currently bills, which is the only truthful
                    // source once the donor has approved a change.
                    'plan_id' => $this->subscriptionPlanId,
                    'links'   => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
                ]);
            }

            // A plan id that names its own price, so a revise onto the wrong
            // one is distinguishable. Answering every plan creation with one id
            // makes any two amounts look alike and the assertion vacuous.
            if (str_contains($path, '/v1/billing/plans')) {
                $sent  = (array) json_decode((string) ($args['body'] ?? ''), true);
                $price = (string) ($sent['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] ?? '0');

                return $this->reply(['id' => 'P-PLAN-' . $price]);
            }

            return $this->reply(['id' => 'OBJ-1']);
        }, 10, 3);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function reply(array $body): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function gateway(): PayPalGateway
    {
        $c = Plugin::instance()->container;

        return new PayPalGateway(
            $c->get(PayPalApi::class),
            $c->get(PayPalAccount::class),
            $c->get(DonationRepository::class),
            $c->get(DonationService::class),
            $c->get(PayPalPlans::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(Clock::class),
            $c->get(PayPalPlanRecorder::class),
        );
    }

    private function plan(int $amountCents = 2500): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $p = RecurringPlan::make();
        $p->donor_id                = 1;
        $p->gateway                 = 'paypal';
        $p->gateway_subscription_id = 'I-AMT-1';
        $p->amount_cents            = $amountCents;
        $p->currency                = 'USD';
        $p->fx_rate                 = sprintf('%.8F', 1);
        $p->base_amount_cents       = $amountCents;
        $p->status                  = 'active';
        $p->is_test                 = true;
        $p->started_at              = $now;
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $req->set_header('content-type', 'application/json');
        foreach ([
            'paypal_transmission_id'   => 'tx-' . bin2hex(random_bytes(3)),
            'paypal_transmission_time' => gmdate('c'),
            'paypal_transmission_sig'  => 'sig',
            'paypal_cert_url'          => 'https://api.paypal.com/cert',
            'paypal_auth_algo'         => 'SHA256withRSA',
        ] as $k => $v) {
            $req->set_header($k, $v);
        }
        $req->set_body((string) wp_json_encode([
            'id'         => 'WH-' . bin2hex(random_bytes(4)),
            'event_type' => $type,
            'resource'   => $resource,
        ]));

        return rest_do_request($req);
    }

    /** Mint a plan id the way the gateway does, so the reverse lookup can find it. */
    private function planIdFor(int $amountCents): string
    {
        return Plugin::instance()->container->get(PayPalPlans::class)
            ->resolvePlan(true, $amountCents, 'USD', 'month', 1);
    }

    public function test_an_approved_amount_change_reaches_the_plan(): void
    {
        $plan   = $this->plan(2500);
        $newest = $this->planIdFor(5000);

        $res = $this->postWebhook('BILLING.SUBSCRIPTION.UPDATED', [
            'id'      => 'I-AMT-1',
            'status'  => 'ACTIVE',
            'plan_id' => $newest,
        ]);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $fresh = RecurringPlan::query()->find('id', (int) $plan->id);
        $this->assertSame(5000, (int) $fresh->amount_cents, 'the donor changed their donation and the row says so');
        $this->assertSame(5000, (int) $fresh->base_amount_cents, 'and the recurring revenue figure follows it');
    }

    /**
     * The card update exists to collect a new funding source and must change
     * nothing else. Built from the local amount it sends PayPal whatever this
     * row last knew, which is how an approved change gets undone.
     */
    public function test_updating_the_card_does_not_revise_the_donor_back_to_an_old_amount(): void
    {
        $plan = $this->plan(2500);

        // PayPal is already billing the larger amount, approved by the donor.
        $this->subscriptionPlanId = $this->planIdFor(5000);
        $this->calls = [];

        $this->gateway()->startPaymentMethodUpdate($plan);

        $revise = null;
        foreach ($this->calls as $call) {
            if (str_contains($call['url'], '/revise')) {
                $revise = $call['body'];
            }
        }

        $this->assertNotNull($revise, 'the card update revises the subscription');
        $this->assertStringContainsString(
            $this->subscriptionPlanId,
            (string) $revise,
            'the revise names the plan PayPal is on, not the one this row remembers'
        );
    }

    /** A plan id this site never minted says nothing, so nothing is guessed. */
    public function test_an_unknown_plan_leaves_the_amount_alone(): void
    {
        $plan = $this->plan(2500);

        $this->postWebhook('BILLING.SUBSCRIPTION.UPDATED', [
            'id'      => 'I-AMT-1',
            'status'  => 'ACTIVE',
            'plan_id' => 'P-BUILT-IN-PAYPALS-DASHBOARD',
        ]);

        $this->assertSame(2500, (int) RecurringPlan::query()->find('id', (int) $plan->id)->amount_cents);
    }
}
