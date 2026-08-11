<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\GatewayReconciler;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlanRecorder;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * A verified webhook is the only automatic way out of processing, so a delivery
 * that is refused or lost strands money PayPal has already taken. The sweep is
 * the second route to the same answer, and because it is the route that runs
 * unattended it has to be at least as careful as the webhook: same amount and
 * currency guard, same mode isolation, and never a second capture.
 */
final class GatewayReconcilerTest extends IntegrationTestCase
{
    private const ORDER = 'ORDER-RECON-1';

    /** @var array<int,array{method:string,url:string}> */
    private array $calls = [];

    /** @var array<string,mixed> */
    private array $capture = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);
        delete_option('dono_gateway_reconcile_cursor');

        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-recon', 'secret-recon');
        $account->saveWebhookId(true, 'WH-RECON-1');

        $this->capture = [
            'id'     => 'CAPTURE-RECON-1',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '40.00'],
        ];

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $this->calls[] = [
                'method' => strtoupper((string) ($args['method'] ?? 'GET')),
                'url'    => $url,
            ];

            if (str_contains($url, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            return $this->reply([
                'id'             => self::ORDER,
                'status'         => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [$this->capture]],
                ]],
            ]);
        }, 10, 3);

        // CoreModule registers PayPal only when credentials exist at boot, and
        // these are created here.
        $c       = Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(RecurringPlanRepository::class),
                $c->get(Clock::class),
                $c->get(PayPalPlanRecorder::class),
            ));
        }
    }

    /** @param array<string,mixed> $body */
    private function reply(array $body, int $code = 200): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'],
            'cookies'  => [], 'filename' => null,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function donationAt(string $status, array $overrides = []): Donation
    {
        $create = new WP_REST_Request('POST', '/dono/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'recon@example.test',
            'amount_cents' => 4000,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Re', 'last_name' => 'Concile'],
        ]));
        $data = rest_do_request($create)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        $donation = $this->find((string) $data['reference']);
        $donation->status            = $status;
        $donation->gateway_intent_id = self::ORDER;
        $donation->created_at        = '2026-08-11 00:00:00';
        foreach ($overrides as $k => $v) {
            $donation->{$k} = $v;
        }
        $donation->save();

        return $this->find($donation->reference);
    }

    private function find(string $reference): Donation
    {
        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    private function sweep(): void
    {
        Plugin::instance()->container->get(GatewayReconciler::class)->run();
    }

    public function test_a_completed_capture_settles_a_stuck_processing_donation(): void
    {
        $donation = $this->donationAt('processing');

        $this->sweep();

        $after = $this->find($donation->reference);
        $this->assertSame('paid', $after->status);
        $this->assertSame('CAPTURE-RECON-1', $after->gateway_txn_id);
    }

    public function test_the_sweep_reads_the_order_and_never_captures_again(): void
    {
        $this->donationAt('processing');

        $this->sweep();

        $orderCalls = array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => str_contains($c['url'], '/v2/checkout/orders/')
        ));

        $this->assertNotEmpty($orderCalls, 'the order is read');
        foreach ($orderCalls as $call) {
            $this->assertSame('GET', $call['method']);
        }

        // The capture carries a stable PayPal-Request-Id per donation, so a
        // second POST replays the first response and would report the hold
        // forever no matter what PayPal did with the money since.
        $this->assertSame(
            [],
            array_values(array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], '/capture'))),
            'no capture is ever re-POSTed'
        );
    }

    public function test_a_capture_for_the_wrong_amount_is_refused(): void
    {
        $this->capture['amount']['value'] = '0.01';
        $donation = $this->donationAt('processing');

        $this->sweep();

        // A signed order is proof PayPal sent it, not that it is about this
        // donation for this amount.
        $this->assertSame('processing', $this->find($donation->reference)->status);
    }

    public function test_a_declined_capture_fails_the_donation(): void
    {
        $this->capture['status'] = 'DECLINED';
        $donation = $this->donationAt('processing');

        $this->sweep();

        $this->assertSame('failed', $this->find($donation->reference)->status);
    }

    public function test_a_still_held_capture_refreshes_the_reason_and_leaves_the_status(): void
    {
        $this->capture['status']         = 'PENDING';
        $this->capture['status_details'] = ['reason' => 'RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION'];
        $donation = $this->donationAt('processing');

        $this->sweep();

        $after = $this->find($donation->reference);
        $meta  = (array) $after->gateway_metadata;

        $this->assertSame('processing', $after->status);
        $this->assertSame('RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION', $meta['paypal_pending_reason'] ?? null);
    }

    public function test_a_pending_donation_whose_capture_completed_is_recovered(): void
    {
        // The capture POST answers after the money moves, so a request that dies
        // in between leaves PayPal paid and nothing written down here.
        $donation = $this->donationAt('pending', ['created_at' => '2026-08-11 00:00:00']);

        $this->sweep();

        $this->assertSame('paid', $this->find($donation->reference)->status);
    }

    public function test_a_pending_donation_whose_capture_did_not_complete_is_left_alone(): void
    {
        $this->capture['status'] = 'DECLINED';
        $donation = $this->donationAt('pending', ['created_at' => '2026-08-11 00:00:00']);

        $this->sweep();

        // An abandoned or retried checkout belongs to the donor's own request.
        $this->assertSame('pending', $this->find($donation->reference)->status);
    }

    public function test_a_donation_from_the_other_mode_is_not_read_at_all(): void
    {
        $donation = $this->donationAt('processing', ['is_test' => false]);

        $this->sweep();

        // Sandbox and live are separate PayPal accounts. With no live keys
        // stored there is nothing to ask, and asking with the sandbox pair
        // would be asking the wrong account about this order.
        $this->assertSame('processing', $this->find($donation->reference)->status);
        $this->assertSame(
            [],
            array_values(array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], '/v2/checkout/orders/')))
        );

        // Left alone, not attempted and failed: an attempt throws, and a throw
        // per donation per run fills a log that keeps only its newest entries.
        $this->assertSame(
            0,
            (int) Event::query()->whereLike('type', 'error.gateway.paypal%')->count(),
            'a mode with no credentials is skipped quietly, not tried and logged'
        );
    }

    public function test_a_recurring_signup_is_not_swept(): void
    {
        $donation = $this->donationAt('processing', ['frequency' => 'monthly']);

        $this->sweep();

        // A recurring signup carries a placeholder intent id and settles through
        // Subscriptions, so there is no Order to read.
        $this->assertSame('processing', $this->find($donation->reference)->status);
    }

    public function test_sweeping_twice_does_not_double_confirm(): void
    {
        $donation = $this->donationAt('processing');

        $this->sweep();
        $paidAt = $this->find($donation->reference)->paid_at;

        $this->sweep();
        $after = $this->find($donation->reference);

        $this->assertSame('paid', $after->status);
        $this->assertSame($paidAt, $after->paid_at, 'confirm() no-ops on an already paid row');
    }
}
