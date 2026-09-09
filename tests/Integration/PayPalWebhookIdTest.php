<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\PayPal\PayPalAccount;
use WP_REST_Request;

/**
 * PayPal verifies a webhook signature by replaying the webhook id back to its
 * own API, so an id that is absent, wrong, or belongs to the other mode makes
 * every delivery fail closed. Nothing downstream can tell that apart from a
 * gateway that has sent nothing, which is why the id is checked before it is
 * stored and never stored unchecked.
 */
final class PayPalWebhookIdTest extends IntegrationTestCase
{
    private const HOOK_OK      = '5ML12345AB678901C';
    private const HOOK_UNKNOWN = '9XX99999ZZ999999Z';

    /** Every event the controller needs a webhook subscribed to. */
    private const WEBHOOK_EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.DENIED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'BILLING.SUBSCRIPTION.UPDATED',
    ];

    /** @var array<int,string> */
    private array $calls = [];

    private int $webhookStatus = 200;
    private bool $webhookTransportFails = false;

    /** @var array<string,mixed> */
    private array $webhookBody = [];

    /** Set to answer the lookup with this verbatim, whatever shape it is. */
    private ?string $webhookBodyRaw = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->account()->forget();

        $this->webhookBody = [
            'id'          => self::HOOK_OK,
            'url'         => 'https://example.test/hook',
            'event_types' => array_map(
                static fn (string $name): array => ['name' => $name],
                self::WEBHOOK_EVENTS
            ),
        ];

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $this->calls[] = $url;

            if (str_contains($url, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400]);
            }

            if (str_contains($url, '/v1/notifications/webhooks/')) {
                if ($this->webhookTransportFails) {
                    return new \WP_Error('http_request_failed', 'Operation timed out');
                }
                if ($this->webhookStatus !== 200) {
                    return $this->reply(
                        ['name' => 'INVALID_RESOURCE_ID', 'message' => 'The requested resource ID was not found.'],
                        $this->webhookStatus
                    );
                }
                if ($this->webhookBodyRaw !== null) {
                    return [
                        'headers'  => [],
                        'body'     => $this->webhookBodyRaw,
                        'response' => ['code' => 200, 'message' => 'OK'],
                        'cookies'  => [], 'filename' => null,
                    ];
                }

                return $this->reply($this->webhookBody);
            }

            return $this->reply([]);
        }, 10, 3);
    }

    private function account(): PayPalAccount
    {
        return Plugin::instance()->container->get(PayPalAccount::class);
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

    /** @param array<string,mixed> $payload */
    private function post(array $payload): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($payload));

        return rest_do_request($req);
    }

    /**
     * A webhook belongs to the PayPal app that issued it, so an id kept against
     * a replaced app names a webhook that cannot exist: every delivery is
     * refused while readiness and the settings card report it as registered.
     * The recurring donor is charged the moment they approve, and the opening
     * sale webhook is the only thing that banks it.
     */
    public function test_replacing_the_app_drops_a_webhook_id_the_new_one_never_had(): void
    {
        $this->account()->saveKeys(true, 'client-old', 'secret-old');
        $this->account()->saveWebhookId(true, self::HOOK_OK);

        // The realistic paste: the WH- event id sitting beside the webhook in
        // PayPal's dashboard, which the lookup refuses.
        $this->webhookStatus = 404;

        $res = $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-new',
            'client_secret' => 'secret-new',
            'webhook_id'    => self::HOOK_UNKNOWN,
        ]);

        $this->assertTrue($this->account()->hasKeysFor(true), 'the new credentials PayPal accepted are saved');
        $this->assertSame('', $this->account()->webhookId(true), 'and the replaced app id is gone');
        $this->assertFalse(
            (bool) ($res->get_data()['account']['webhook_test'] ?? true),
            'the card reads "no webhook id saved" rather than reporting one'
        );
    }

    /** The same when the field is left blank, which is the other way to save. */
    public function test_replacing_the_app_with_a_blank_field_drops_it_too(): void
    {
        $this->account()->saveKeys(true, 'client-old', 'secret-old');
        $this->account()->saveWebhookId(true, self::HOOK_OK);

        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-new',
            'client_secret' => 'secret-new',
        ]);

        $this->assertSame('', $this->account()->webhookId(true));
    }

    public function test_rotating_a_secret_on_the_same_app_keeps_its_webhook(): void
    {
        $this->account()->saveKeys(true, 'client-same', 'secret-old');
        $this->account()->saveWebhookId(true, self::HOOK_OK);

        $this->webhookStatus = 404;

        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-same',
            'client_secret' => 'secret-rotated',
            'webhook_id'    => self::HOOK_UNKNOWN,
        ]);

        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(true));
    }

    public function test_a_rejected_webhook_id_does_not_discard_the_credentials_it_came_with(): void
    {
        $this->webhookStatus = 404;

        $res = $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-keep',
            'client_secret' => 'secret-keep',
            'webhook_id'    => self::HOOK_UNKNOWN,
        ]);

        // PayPal minted a token, so the pair is proven. Throwing it away over a
        // separate field would leave a first-time setup with no PayPal at all
        // and nothing on screen saying the credentials were the good part.
        $this->assertTrue($this->account()->hasKeysFor(true), 'verified credentials survive');
        $this->assertSame('', $this->account()->webhookId(true), 'the rejected id is not stored');
        $this->assertNotEmpty(
            (array) ($res->get_data()['webhook_warning'] ?? []),
            'and the admin is told the id was refused'
        );
    }

    public function test_an_unreachable_paypal_never_stores_an_unchecked_id(): void
    {
        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-a',
            'client_secret' => 'secret-a',
            'webhook_id'    => self::HOOK_OK,
        ]);
        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(true));

        $this->webhookTransportFails = true;

        $req = new WP_REST_Request('POST', '/gratora/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['mode' => 'test', 'webhook_id' => self::HOOK_UNKNOWN]));
        rest_do_request($req);

        // An id nobody confirmed, written over one that works, is the whole
        // incident: verification then replays the wrong id and every delivery
        // is refused with nothing saying why.
        $this->assertSame(
            self::HOOK_OK,
            $this->account()->webhookId(true),
            'a timeout must not overwrite a working webhook id'
        );
    }

    public function test_the_webhook_id_can_be_saved_on_its_own_against_stored_keys(): void
    {
        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-b',
            'client_secret' => 'secret-b',
        ]);
        $this->assertSame('', $this->account()->webhookId(true));

        $req = new WP_REST_Request('POST', '/gratora/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['mode' => 'test', 'webhook_id' => self::HOOK_OK]));
        $res = rest_do_request($req);

        // The secret is never shown again, so requiring it back to add the id
        // is what leaves the field empty on a site that already has keys.
        $this->assertLessThan(300, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(true));
        $this->assertTrue($this->account()->hasKeysFor(true), 'the credentials are untouched');
    }

    public function test_a_webhook_id_can_be_removed_without_losing_the_credentials(): void
    {
        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-c',
            'client_secret' => 'secret-c',
            'webhook_id'    => self::HOOK_OK,
        ]);
        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(true));

        $del = new WP_REST_Request('DELETE', '/gratora/v1/gateways/paypal/webhook');
        $del->set_param('mode', 'test');
        $res = rest_do_request($del);

        $this->assertLessThan(300, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('', $this->account()->webhookId(true), 'the id is cleared');
        $this->assertTrue($this->account()->hasKeysFor(true), 'the credentials stay');
    }

    public function test_a_webhook_only_save_of_an_id_paypal_does_not_know_is_refused(): void
    {
        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-d',
            'client_secret' => 'secret-d',
            'webhook_id'    => self::HOOK_OK,
        ]);

        $this->webhookStatus = 404;

        $req = new WP_REST_Request('POST', '/gratora/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['mode' => 'test', 'webhook_id' => self::HOOK_UNKNOWN]));
        $res = rest_do_request($req);

        $this->assertGreaterThanOrEqual(400, $res->get_status());
        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(true), 'the working id stays');
        $this->assertTrue($this->account()->hasKeysFor(true), 'and so do the credentials');
    }

    public function test_the_live_slot_is_not_touched_by_a_sandbox_save(): void
    {
        $this->post([
            'mode'          => 'live',
            'client_id'     => 'client-live',
            'client_secret' => 'secret-live',
            'webhook_id'    => self::HOOK_OK,
        ]);

        $this->post([
            'mode'          => 'test',
            'client_id'     => 'client-test',
            'client_secret' => 'secret-test',
            'webhook_id'    => self::HOOK_OK,
        ]);

        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(false));
        $this->assertSame(self::HOOK_OK, $this->account()->webhookId(true));
        $this->assertTrue($this->account()->hasKeysFor(false));
    }

    /**
     * A proxy, WAF or captive portal answers the lookup 200 with something that
     * is not a webhook. Graded as found, the id was written on the strength of
     * an answer PayPal never gave, and every later delivery failed its
     * signature check while the card said the id was confirmed.
     */
    public function test_a_2xx_that_is_not_a_webhook_never_stores_the_id(): void
    {
        $this->post(['mode' => 'test', 'client_id' => 'client-a', 'client_secret' => 'secret-a']);

        $this->webhookBodyRaw = '<html><body>Sign in to continue</body></html>';

        $this->post(['mode' => 'test', 'webhook_id' => self::HOOK_UNKNOWN]);

        $this->assertSame('', $this->account()->webhookId(true));
    }

    public function test_a_webhook_subscribed_to_nothing_is_reported_as_incomplete(): void
    {
        $this->webhookBody = ['id' => self::HOOK_OK, 'url' => 'https://example.test/hook', 'event_types' => []];

        $this->post([
            'mode'          => 'test',
            'client_id'     => 'c',
            'client_secret' => 's',
            'webhook_id'    => self::HOOK_OK,
        ]);

        $errors = Event::query()->where('type', ErrorLog::PREFIX . 'gateway.paypal')->orderBy('id', 'DESC')->getAll();

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('PAYMENT.CAPTURE.COMPLETED', (string) ($errors[0]->payload['message'] ?? ''));
    }
}
