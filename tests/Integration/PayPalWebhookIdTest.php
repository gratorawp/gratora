<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\PayPal\PayPalAccount;
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

    /** @var array<int,string> */
    private array $calls = [];

    private int $webhookStatus = 200;
    private bool $webhookTransportFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->account()->forget();

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
                return $this->reply(['id' => self::HOOK_OK, 'url' => 'https://example.test/hook']);
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
        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($payload));

        return rest_do_request($req);
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

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
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

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
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

        $del = new WP_REST_Request('DELETE', '/dono/v1/gateways/paypal/webhook');
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

        $req = new WP_REST_Request('POST', '/dono/v1/gateways/paypal/keys');
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
}
