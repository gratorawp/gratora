<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Receipts\Receipt;
use WP_REST_Request;

/** THROWAWAY PROBE - delete before finishing. */
final class ZzProbeRefundLensTest extends IntegrationTestCase
{
    private string $refundStatus = 'COMPLETED';

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD', 'JPY', 'EUR'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

        $this->mockPayPal();

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Gateways\PayPal\PayPalPlanRecorder::class),
            ));
        }
    }

    private function mockPayPal(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $body = [];
            if (! empty($args['body']) && is_string($args['body'])) {
                $decoded = json_decode($args['body'], true);
                $body = is_array($decoded) ? $decoded : [];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($this->canned($path, $body)),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function canned(string $path, array $body): array
    {
        if (str_contains($path, '/v1/oauth2/token')) {
            return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        }
        if (str_contains($path, '/verify-webhook-signature')) {
            return ['verification_status' => 'SUCCESS'];
        }
        if (str_ends_with($path, '/capture')) {
            return [
                'id'     => 'ORDER-1',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => ['captures' => [[
                        'id'     => 'CAPTURE-1',
                        'status' => 'COMPLETED',
                    ]]],
                ]],
                'payer' => ['email_address' => 'donor@example.test'],
            ];
        }
        if (str_ends_with($path, '/refund')) {
            // PayPal's refund_status enum: CANCELLED, FAILED, PENDING, COMPLETED.
            return [
                'id'     => 'REFUND-PENDING-1',
                'status' => $this->refundStatus,
                'amount' => [
                    'value'         => (string) ($body['amount']['value'] ?? '0'),
                    'currency_code' => (string) ($body['amount']['currency_code'] ?? 'USD'),
                ],
                'status_details' => ['reason' => 'ECHECK'],
            ];
        }
        if (str_contains($path, '/v2/checkout/orders')) {
            return ['id' => 'ORDER-1', 'status' => 'CREATED'];
        }
        return ['id' => 'OBJ-1'];
    }

    private function paidPayPalDonation(int $amount = 5000, string $currency = 'USD'): \Dono\Donations\Donation
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'probe@example.test',
            'amount_cents' => $amount,
            'currency'     => $currency,
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Pro', 'last_name' => 'Be', 'country' => 'US'],
        ]));
        $reference = rest_do_request($req)->get_data()['reference'];

        $cap = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $cap->set_header('content-type', 'application/json');
        $cap->set_body((string) wp_json_encode([
            'reference'    => $reference,
            'status_token' => $this->stampStatusToken($reference),
        ]));
        rest_do_request($cap);
        $this->runPendingAsyncJobs();

        return $this->donations()->findByReference($reference);
    }

    public function test_probe_paypal_pending_refund_is_banked_as_settled(): void
    {
        $this->refundStatus = 'PENDING';

        $donation = $this->paidPayPalDonation();
        $mails    = $this->captureMails();

        $refund = $this->svc()->refund($donation, 5000, 'donor asked');

        $fresh = $this->donations()->findByReference($donation->reference);

        fwrite(STDERR, "\n--- PROBE paypal PENDING refund ---\n");
        fwrite(STDERR, 'refund row status: ' . $refund->status . "\n");
        fwrite(STDERR, 'refund metadata: ' . wp_json_encode($refund->metadata) . "\n");
        fwrite(STDERR, 'donation status: ' . $fresh->status . "\n");
        fwrite(STDERR, 'donation refunded_cents: ' . $fresh->refunded_cents . "\n");
        $receipts = Receipt::query()->where('donation_id', $donation->id)->getAll();
        foreach ($receipts as $r) {
            fwrite(STDERR, 'receipt voided: ' . var_export((bool) $r->voided, true) . "\n");
        }
        foreach ($mails as $m) {
            fwrite(STDERR, 'mail: ' . (string) $m['subject'] . "\n");
        }

        // Second refund attempt (the org trying again once PayPal fails the first)
        try {
            $this->svc()->refund($fresh, 5000, 'retry');
            fwrite(STDERR, "retry: ACCEPTED\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, 'retry blocked: ' . $e->getMessage() . "\n");
        }

        $this->assertTrue(true);
    }

    public function test_probe_paypal_failed_refund_status(): void
    {
        $this->refundStatus = 'FAILED';
        $donation = $this->paidPayPalDonation();
        try {
            $this->svc()->refund($donation, 5000);
            fwrite(STDERR, "\nFAILED status: accepted (bad)\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nFAILED status rejected: " . $e->getMessage() . "\n");
        }
        $this->assertTrue(true);
    }

    private function svc(): DonationService
    {
        return Plugin::instance()->container->get(DonationService::class);
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }
}
