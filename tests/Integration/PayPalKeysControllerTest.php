<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Foundation\Plugin;
use Dono\Gateways\PayPal\PayPalAccount;
use WP_REST_Request;

/**
 * Saving PayPal credentials exchanges them for a token first, so a wrong secret
 * or a sandbox app pasted into live is caught at save time. Bad credentials are
 * never left behind, and a stored secret is never readable back over REST.
 */
final class PayPalKeysControllerTest extends IntegrationTestCase
{
    /** @var array<int,array{url:string,auth:string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->account()->forget();
    }

    /**
     * Resolve through the container: the encryption key is DB-backed and
     * generated on first use, so a fresh Crypto can hold a different key than
     * the booted singleton the controller encrypted with.
     */
    private function account(): PayPalAccount
    {
        return Plugin::instance()->container->get(PayPalAccount::class);
    }

    /** Intercept the OAuth token exchange; $ok=false makes PayPal reject it. */
    private function mockPayPal(bool $ok = true): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($ok) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $this->calls[] = [
                'url'  => $url,
                'auth' => (string) ($args['headers']['Authorization'] ?? ''),
            ];

            if (! $ok) {
                return [
                    'headers'  => [],
                    'body'     => (string) wp_json_encode([
                        'error' => 'invalid_client',
                        'error_description' => 'Client Authentication failed',
                    ]),
                    'response' => ['code' => 401, 'message' => 'Unauthorized'],
                    'cookies'  => [], 'filename' => null,
                ];
            }
            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode([
                    'access_token' => 'A21AAF_test_token',
                    'expires_in'   => 32400,
                    'token_type'   => 'Bearer',
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 10, 3);
    }

    private function save(string $mode, string $clientId, string $secret, string $webhookId = ''): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode' => $mode, 'client_id' => $clientId, 'client_secret' => $secret, 'webhook_id' => $webhookId,
        ]));
        return rest_do_request($req);
    }

    public function test_valid_credentials_are_verified_and_stored(): void
    {
        $this->mockPayPal();
        $res = $this->save('test', 'AeA1QIZ_client', 'EO422dn3_secret', 'WH-TEST-1');

        $this->assertSame(200, $res->get_status());

        // The token exchange used HTTP basic auth with the pair just submitted.
        $this->assertNotEmpty($this->calls, 'PayPal was called to verify');
        $this->assertStringContainsString('/v1/oauth2/token', $this->calls[0]['url']);
        $this->assertSame(
            'Basic ' . base64_encode('AeA1QIZ_client:EO422dn3_secret'),
            $this->calls[0]['auth']
        );
        // Sandbox credentials must go to the sandbox host, not live.
        $this->assertStringContainsString('sandbox', $this->calls[0]['url']);

        $acct = $this->account();
        $this->assertTrue($acct->hasKeysFor(true), 'sandbox credentials stored');
        $this->assertFalse($acct->hasKeysFor(false), 'live credentials untouched');
        $this->assertSame('EO422dn3_secret', $acct->secretFor(true), 'secret round-trips through encryption');
        $this->assertSame('WH-TEST-1', $acct->webhookId(true));
    }

    public function test_secret_is_never_returned_over_rest(): void
    {
        $this->mockPayPal();
        $body = $this->save('test', 'AeA1QIZ_client', 'EO422dn3_supersecret')->get_data();

        $encoded = (string) wp_json_encode($body);
        $this->assertStringNotContainsString('EO422dn3_supersecret', $encoded, 'the secret must never leave the server');
        $this->assertSame('cret', $body['account']['secret_hint_test'] ?? '', 'only a last-4 hint is exposed');
        // The client id is public by design and is needed by the SDK.
        $this->assertSame('AeA1QIZ_client', $body['account']['client_id_test'] ?? '');
    }

    public function test_credentials_rejected_by_paypal_are_not_persisted(): void
    {
        $this->mockPayPal(false);
        $res = $this->save('test', 'AeA1QIZ_wrong', 'EO422dn3_wrong');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_paypal_key_rejected', $res->get_data()['code'] ?? null);
        $this->assertFalse(
            $this->account()->hasKeysFor(true),
            'credentials PayPal rejected must not be left behind'
        );
    }

    public function test_live_credentials_go_to_the_live_host(): void
    {
        $this->mockPayPal();
        $this->save('live', 'AeA1QIZ_live', 'EO422dn3_live');

        $this->assertNotEmpty($this->calls);
        $this->assertStringNotContainsString('sandbox', $this->calls[0]['url'], 'live must not hit the sandbox host');
    }

    public function test_empty_credentials_are_refused_before_calling_paypal(): void
    {
        $this->mockPayPal();
        $res = $this->save('test', '', '');

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_paypal_bad_key', $res->get_data()['code'] ?? null);
        $this->assertEmpty($this->calls, 'no PayPal call is spent on obviously empty input');
    }

    public function test_removing_one_mode_leaves_the_other(): void
    {
        $this->mockPayPal();
        $this->save('test', 'AeA1QIZ_t', 'EO422dn3_t');
        $this->save('live', 'AeA1QIZ_l', 'EO422dn3_l');
        $this->assertTrue($this->account()->hasKeysFor(true));
        $this->assertTrue($this->account()->hasKeysFor(false));

        $req = new WP_REST_Request('DELETE', '/dono/v1/gateways/paypal/keys');
        $req->set_param('mode', 'live');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($this->account()->hasKeysFor(true), 'sandbox survives removing live');
        $this->assertFalse($this->account()->hasKeysFor(false));
    }

    /**
     * Regression: canCharge answers "may the form offer PayPal", which is asked
     * before any donation has fixed a mode. It once keyed off the per-operation
     * mode override on the shared account instance, so a live-mode call earlier
     * in the request hid PayPal from a sandbox-only site entirely.
     */
    public function test_can_charge_ignores_the_transient_mode_override(): void
    {
        $this->mockPayPal();
        $this->save('test', 'AeA1QIZ_client', 'EO422dn3_secret');

        $acct = $this->account();
        $acct->useTestMode(false);

        $this->assertTrue(
            $acct->canCharge(),
            'sandbox-only credentials must still let the form offer PayPal'
        );
    }

    public function test_saving_requires_the_settings_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->mockPayPal();

        $res = $this->save('test', 'AeA1QIZ_client', 'EO422dn3_secret');

        $this->assertSame(403, $res->get_status());
        $this->assertFalse($this->account()->hasKeysFor(true));
    }

    /**
     * Credentials have to be written before the token call can test them, so a
     * failure has to put the old ones back. Blanking the mode instead takes the
     * webhook id with it, and without that id recurring stops being offered:
     * a five-second PayPal wobble during a routine secret rotation would have
     * quietly turned every monthly donor into a one-off.
     */
    public function test_a_failed_re_save_leaves_the_working_credentials_alone(): void
    {
        $account = Plugin::instance()->container->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'GOOD_client', 'GOOD_secret');
        $account->saveWebhookId(true, 'WH-WORKING');

        // The token call is the credential check; make it fail.
        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (is_string($url) && str_contains($url, 'oauth2/token')) {
                return new \WP_Error('http_request_failed', 'cURL error 28: timed out');
            }
            return $pre;
        }, 5, 3);

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'mode' => 'test', 'client_id' => 'NEW_client', 'client_secret' => 'NEW_secret',
        ]));
        $res = rest_do_request($req);

        $this->assertGreaterThanOrEqual(400, $res->get_status(), 'the save is refused');
        $this->assertSame('WH-WORKING', $account->webhookId(true), 'the webhook id survives');
        $this->assertTrue($account->hasKeysFor(true), 'and so do the credentials that worked');
    }

    public function test_recurring_is_not_offered_without_a_webhook_id(): void
    {
        $c = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1_client', 'EO42_secret');
        update_option('dono_gateway_config', ['test_mode' => true]);

        $gateway = new \Dono\Gateways\PayPal\PayPalGateway(
            $c->get(\Dono\Gateways\PayPal\PayPalApi::class),
            $account,
            $c->get(\Dono\Donations\DonationRepository::class),
            $c->get(\Dono\Donations\DonationService::class),
            $c->get(\Dono\Gateways\PayPal\PayPalPlans::class),
            $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            $c->get(\Dono\Foundation\Time\Clock::class),
        );

        $this->assertSame(['one_time'], $gateway->frequencies(), 'no webhook, no recurring');

        $account->saveWebhookId(true, 'WH-1');
        $this->assertContains('recurring', $gateway->frequencies(), 'and it comes back with one');
    }

    public function test_paypal_does_not_offer_currencies_it_would_reject(): void
    {
        $c = Plugin::instance()->container;
        $gateway = new \Dono\Gateways\PayPal\PayPalGateway(
            $c->get(\Dono\Gateways\PayPal\PayPalApi::class),
            $c->get(PayPalAccount::class),
            $c->get(\Dono\Donations\DonationRepository::class),
            $c->get(\Dono\Donations\DonationService::class),
            $c->get(\Dono\Gateways\PayPal\PayPalPlans::class),
            $c->get(\Dono\Recurring\RecurringPlanRepository::class),
            $c->get(\Dono\Foundation\Time\Clock::class),
        );

        // PayPal rejects decimals on these two, and PayPalMoney sends decimals
        // for both because Currency::minorUnits answers 2 for Stripe's sake.
        foreach (['HUF', 'TWD'] as $code) {
            $this->assertNotContains($code, $gateway->currencies(), $code . ' would fail at the boundary');
            $this->assertStringContainsString('.', \Dono\Gateways\PayPal\PayPalMoney::toValue(100000, $code));
        }

        $this->assertContains('JPY', $gateway->currencies(), 'JPY is genuinely zero-decimal and works');
        $this->assertSame('1000', \Dono\Gateways\PayPal\PayPalMoney::toValue(100000, 'JPY'));
    }

    /**
     * A webhook that exists is not a webhook that delivers anything this reads.
     * Reported as checked, an org can save an id subscribed to nothing Dono
     * handles and be told it is fine, and then every recurring donation is
     * charged with no event to bank it.
     */
    public function test_a_webhook_missing_the_events_dono_reads_is_saved_and_reported(): void
    {
        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            if (str_contains($url, 'oauth2/token')) {
                $body = ['access_token' => 'A21AAF_test_token', 'expires_in' => 32400, 'token_type' => 'Bearer'];
            } elseif (str_contains($url, '/notifications/webhooks/')) {
                $body = [
                    'id'          => 'WH-PARTIAL',
                    'url'         => 'https://example.test/webhook',
                    // Captures only: nothing here reports a subscription at all.
                    'event_types' => [
                        ['name' => 'PAYMENT.CAPTURE.COMPLETED'],
                        ['name' => 'PAYMENT.CAPTURE.REFUNDED'],
                    ],
                ];
            } else {
                $body = ['id' => 'OBJ-1'];
            }

            return [
                'headers'  => [],
                'body'     => (string) wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [], 'filename' => null,
            ];
        }, 1, 3);

        $res = $this->save('test', 'AeA1QIZ_client', 'EO422dn3_secret', 'WH-PARTIAL');

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(
            'WH-PARTIAL',
            $this->account()->webhookId(true),
            'a setup that is otherwise right is not blocked over the events it does not need'
        );

        $errors = Event::query()
            ->where('type', ErrorLog::PREFIX . 'gateway.paypal')
            ->orderBy('id', 'DESC')
            ->getAll();

        $this->assertNotSame([], $errors, 'the gap is written where the org reads about problems');
        $this->assertStringContainsString(
            'BILLING.SUBSCRIPTION.ACTIVATED',
            (string) ($errors[0]->payload['message'] ?? ''),
            'and it names what is missing'
        );
    }
}
