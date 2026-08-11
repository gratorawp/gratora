<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Crypto\Crypto;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;

/**
 * What actually goes on the wire.
 *
 * Both suites fake PayPal by filtering pre_http_request and answering with a
 * canned success, so every one of them passed while the real API refused the
 * call: capture sends no fields, an empty PHP array encodes as [], and PayPal
 * reads that as malformed JSON. A donor's card was accepted and the money was
 * never collected.
 */
final class PayPalRequestBodyTest extends IntegrationTestCase
{
    /** @var list<array{url:string,body:string}> */
    private array $sent = [];

    private function api(): PayPalApi
    {
        $account = new PayPalAccount(new Crypto());
        $account->saveKeys(true, 'client', 'secret');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            if (str_contains($url, 'oauth2/token')) {
                return $this->ok(['access_token' => 'tok', 'expires_in' => 3600]);
            }

            $this->sent[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];

            return $this->ok(['id' => 'X', 'status' => 'COMPLETED']);
        }, 5, 3);

        return new PayPalApi($account);
    }

    /** @param array<string,mixed> $payload */
    private function ok(array $payload): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($payload),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [], 'filename' => null,
        ];
    }

    public function test_a_body_with_no_fields_is_sent_as_an_object(): void
    {
        $this->api()->post('/v2/checkout/orders/ORDER-1/capture', []);

        $body = end($this->sent)['body'];

        $this->assertSame('{}', $body, 'PayPal refuses [] as malformed JSON');
        $this->assertIsObject(json_decode($body), 'and it must decode as an object');
    }

    public function test_a_body_with_fields_is_still_an_object(): void
    {
        $this->api()->post('/v2/checkout/orders', ['intent' => 'CAPTURE']);

        $decoded = json_decode(end($this->sent)['body']);

        $this->assertIsObject($decoded);
        $this->assertSame('CAPTURE', $decoded->intent);
    }
}
