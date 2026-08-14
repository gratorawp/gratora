<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlanRecorder;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * Both public PayPal routes run in a donor's browser at the one moment they
 * cannot tell whether their money moved. What PayPal says when it refuses is
 * written for whoever integrated it: it names the HTTP verb, the API path and
 * the sandbox or live mode, in English whatever the site's locale, and it says
 * nothing at all about the donation.
 *
 * The refusal still has to reach the org, so the raw text belongs in the error
 * log and the plain-language sentence belongs on screen.
 */
final class PayPalDonorErrorCopyTest extends IntegrationTestCase
{
    private const ORDER = 'ORDER-COPY-1';

    /** @var array<string,array{status:int,body:array<string,mixed>}> */
    private array $failures = [];

    private string $currentReference = '';

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['USD'],
        ]);

        $c       = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'client-copy', 'secret-copy');
        $account->saveWebhookId(true, 'WH-COPY-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            foreach ($this->failures as $needle => $failure) {
                if (str_contains($path, (string) $needle)) {
                    unset($this->failures[$needle]);
                    return $this->reply($failure['body'], $failure['status']);
                }
            }

            if (str_contains($path, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }
            if (str_contains($path, '/v1/catalogs/products')) return $this->reply(['id' => 'PROD-COPY']);
            if (str_contains($path, '/v1/billing/plans'))     return $this->reply(['id' => 'P-PLAN-COPY']);

            return $this->reply(['id' => self::ORDER, 'status' => 'CREATED']);
        }, 10, 3);

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

    /** @param array<string,mixed> $body */
    private function failNext(string $needle, int $status, array $body): void
    {
        $this->failures[$needle] = ['status' => $status, 'body' => $body];
    }

    private function newDonation(string $frequency = 'one_time'): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => 'copy' . bin2hex(random_bytes(3)) . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'paypal',
            'frequency'    => $frequency,
            'profile'      => ['first_name' => 'Copy', 'last_name' => 'Probe'],
        ]));
        $data = rest_do_request($req)->get_data();
        $this->assertArrayHasKey('reference', $data, (string) wp_json_encode($data));

        $repo     = Plugin::instance()->container->get(DonationRepository::class);
        $donation = $repo->findByReference((string) $data['reference']);
        $donation->gateway_intent_id = self::ORDER;
        $donation->save();

        $this->currentReference = (string) $data['reference'];

        return $this->currentReference;
    }

    /** @return array{0:int,1:string} */
    private function dispatchCapture(string $reference): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/capture');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'    => $reference,
            'status_token' => $this->stampStatusToken($reference),
        ]));
        $res = rest_do_request($req);

        return [$res->get_status(), (string) ($res->get_data()['message'] ?? '')];
    }

    /** @return array{0:int,1:string} */
    private function dispatchSubscription(string $reference): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/subscription');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'reference'       => $reference,
            'status_token'    => $this->stampStatusToken($reference),
            'subscription_id' => 'I-SUB-COPY-1',
        ]));
        $res = rest_do_request($req);

        return [$res->get_status(), (string) ($res->get_data()['message'] ?? '')];
    }

    private function loggedErrors(string $source): array
    {
        return Event::query()->whereLike('type', 'error.' . $source . '%')->getAll();
    }

    public function test_a_declined_capture_reads_as_a_sentence_about_the_donation(): void
    {
        $reference = $this->newDonation();
        $this->failNext('/capture', 422, [
            'name'    => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was either declined by the processor or bank.']],
        ]);

        [$status, $message] = $this->dispatchCapture($reference);

        $this->assertSame(400, $status);
        $this->assertStringNotContainsString('PayPal API', $message, 'no API call is described to a donor');
        $this->assertStringNotContainsString('/v2/checkout/orders', $message);
        $this->assertStringNotContainsString('INSTRUMENT_DECLINED', $message);
        $this->assertStringContainsString('PayPal could not complete this donation.', $message);
    }

    public function test_the_refusal_the_donor_is_spared_still_reaches_the_org(): void
    {
        $reference = $this->newDonation();
        $this->failNext('/capture', 422, [
            'name'    => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'Declined by the bank.']],
        ]);

        $this->dispatchCapture($reference);

        $errors = $this->loggedErrors('gateway.paypal.capture');
        $this->assertCount(1, $errors, 'a failure nobody records is a failure nobody can answer for');

        $payload = (array) $errors[0]->payload;
        $this->assertStringContainsString('Declined by the bank.', (string) ($payload['message'] ?? ''));
        $this->assertStringContainsString('/v2/checkout/orders', (string) ($payload['message'] ?? ''));
        $this->assertSame($reference, (string) ($payload['reference'] ?? ''));
    }

    /**
     * PayPal has taken the first payment by the time the browser calls this
     * route, so copy that reads as a failed donation sends the donor round to
     * approve a second subscription.
     */
    public function test_a_failed_subscription_lookup_does_not_read_as_a_failed_donation(): void
    {
        $reference = $this->newDonation('monthly');
        $this->failNext('/v1/billing/subscriptions/', 401, [
            'error'             => 'invalid_token',
            'error_description' => 'Authentication failed due to invalid authentication credentials.',
        ]);

        [$status, $message] = $this->dispatchSubscription($reference);

        $this->assertSame(400, $status);
        $this->assertStringNotContainsString('PayPal API', $message);
        $this->assertStringNotContainsString('/v1/billing/subscriptions', $message);
        $this->assertStringNotContainsString('invalid_token', $message);
        $this->assertStringContainsString('PayPal has your donation', $message);
        $this->assertStringContainsString('no need to donate again', $message);

        $errors = $this->loggedErrors('gateway.paypal.subscription');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(
            'invalid authentication credentials',
            (string) ((array) $errors[0]->payload)['message']
        );
    }
}
