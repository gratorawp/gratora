<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
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
final class ZzPayPalRefundStatusProbeTest extends IntegrationTestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $body = [];
            if (! empty($args['body']) && is_string($args['body'])) {
                $d = json_decode($args['body'], true);
                $body = is_array($d) ? $d : [];
            }
            $this->calls[] = ['url' => $url, 'body' => $body];
            return [
                'headers' => [], 'cookies' => [], 'filename' => null,
                'response' => ['code' => 200, 'message' => 'OK'],
                'body' => (string) wp_json_encode($this->canned($path, $body)),
            ];
        }, 10, 3);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('paypal')) {
            $manager->register(new PayPalGateway(
                $c->get(PayPalApi::class),
                $account,
                $c->get(DonationRepository::class),
                $c->get(\Dono\Donations\DonationService::class),
                $c->get(PayPalPlans::class),
                $c->get(\Dono\Recurring\RecurringPlanRepository::class),
                $c->get(\Dono\Foundation\Time\Clock::class),
                $c->get(\Dono\Gateways\PayPal\PayPalPlanRecorder::class),
            ));
        }
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function canned(string $path, array $body): array
    {
        if (str_contains($path, '/v1/oauth2/token')) return ['access_token' => 'A21AAF_test', 'expires_in' => 32400];
        if (str_contains($path, '/verify-webhook-signature')) return ['verification_status' => 'SUCCESS'];
        if (str_contains($path, '/capture')) {
            return [
                'id' => 'ORDER-1', 'status' => 'COMPLETED',
                'purchase_units' => [[ 'payments' => ['captures' => [[
                    'id' => 'CAPTURE-1', 'status' => 'COMPLETED',
                ]]]]],
                'payer' => ['email_address' => 'donor@example.test'],
            ];
        }
        if (str_contains($path, '/v2/checkout/orders')) return ['id' => 'ORDER-1', 'status' => 'CREATED'];
        return ['id' => 'OBJ-1'];
    }

    public function test_probe_pending_paypal_refund_webhook_is_banked_as_succeeded(): void
    {
        $reference = $this->paidDonation();
        $this->runPendingAsyncJobs();

        $before = $this->donations()->findByReference($reference);
        fwrite(STDERR, "\nBEFORE status={$before->status} refunded={$before->refunded_cents}\n");

        $mails = $this->captureMails();

        // PayPal's own refund resource: status is part of the object and this
        // one has NOT paid the donor. The plugin's own test omits status.
        $res = $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id'        => 'REFUND-PENDING-1',
            'status'    => 'PENDING',
            'custom_id' => $reference,
            'amount'    => ['value' => '25.00', 'currency_code' => 'USD'],
            'status_details' => ['reason' => 'ECHECK'],
        ]);
        fwrite(STDERR, 'WEBHOOK HTTP ' . $res->get_status() . "\n");

        $after = $this->donations()->findByReference($reference);
        fwrite(STDERR, "AFTER status={$after->status} refunded={$after->refunded_cents}\n");

        $r = Refund::query()->where('gateway_refund_id', 'REFUND-PENDING-1')->get();
        fwrite(STDERR, 'REFUND ROW status=' . ($r ? $r->status : 'NONE')
            . ' amount=' . ($r ? $r->amount_cents : '-') . "\n");

        foreach (Receipt::query()->where('donation_id', $after->id)->getAll() as $rc) {
            fwrite(STDERR, "RECEIPT {$rc->receipt_number} voided=" . var_export((bool) $rc->voided, true) . "\n");
        }

        foreach (iterator_to_array($mails) as $m) {
            fwrite(STDERR, 'MAIL: ' . ($m['subject'] ?? '') . "\n");
        }

        $rows = $this->donations()->paidForDonorInYear((int) $after->donor_id, (int) gmdate('Y'));
        fwrite(STDERR, 'STATEMENT ROWS: ' . json_encode($rows) . "\n");

        $this->assertTrue(true);
    }

    public function test_probe_failed_paypal_refund_webhook(): void
    {
        $reference = $this->paidDonation();
        $this->runPendingAsyncJobs();

        $this->postWebhook('PAYMENT.CAPTURE.REFUNDED', [
            'id'        => 'REFUND-FAILED-1',
            'status'    => 'FAILED',
            'custom_id' => $reference,
            'amount'    => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        $after = $this->donations()->findByReference($reference);
        fwrite(STDERR, "\nFAILED-REFUND EVENT -> status={$after->status} refunded={$after->refunded_cents}\n");
        $this->assertTrue(true);
    }

    /** @param array<string,mixed> $resource */
    private function postWebhook(string $type, array $resource): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/webhooks/paypal');
        $req->set_header('content-type', 'application/json');
        foreach ([
            'paypal_transmission_id'   => 'tx-1',
            'paypal_transmission_time' => gmdate('c'),
            'paypal_transmission_sig'  => 'sig',
            'paypal_cert_url'          => 'https://api.paypal.com/cert',
            'paypal_auth_algo'         => 'SHA256withRSA',
        ] as $k => $v) {
            $req->set_header($k, $v);
        }
        $req->set_body((string) wp_json_encode([
            'id'         => 'WH-EVT-' . bin2hex(random_bytes(4)),
            'event_type' => $type,
            'resource'   => $resource,
        ]));
        return rest_do_request($req);
    }

    private function paidDonation(): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'ppprobe@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Pay', 'last_name' => 'Pal', 'country' => 'US'],
        ]));
        $reference = rest_do_request($req)->get_data()['reference'];

        $this->postWebhook('PAYMENT.CAPTURE.COMPLETED', [
            'id' => 'CAPTURE-1', 'custom_id' => $reference,
            'status' => 'COMPLETED',
            'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
        ]);

        return $reference;
    }

    private function donations(): DonationRepository
    {
        return Plugin::instance()->container->get(DonationRepository::class);
    }
}
