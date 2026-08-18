<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Stripe gateway read against Stripe's own schema.
 *
 * Every suite that exercises this gateway answers Stripe from a fixture, so a
 * field the API does not have reads back exactly as well as one it does, and a
 * request the API would refuse gets a 200. That has already cost this codebase
 * twice: a charge fetched with `expand[]=dispute`, which Stripe refuses on the
 * whole request, and two branches keyed on `charge.dispute`, which does not
 * exist and so could never fire. Both passed every test.
 *
 * The fixture beside this test is an extract of Stripe's published OpenAPI
 * specification at the exact version StripeApi::API_VERSION pins, taken from
 * the commit whose info.version reports it. It is the one source here that does
 * not agree with the code by construction.
 *
 * This is a static read of the source, not a run of it: it cannot see a field
 * read through a variable, and it is not a substitute for a donation put
 * through a real test account. What it does catch is the whole class of
 * mistake where the code and its fixtures agree with each other about a shape
 * Stripe never returns.
 */
final class StripeSchemaConformanceTest extends TestCase
{
    /**
     * Variables in the gateway that hold a decoded Stripe object, and which
     * object each one holds. Anything read off them has to exist on it.
     */
    private const RESPONSE_VARS = [
        'charge'   => 'charge',
        'intent'   => 'payment_intent',
        'sub'      => 'subscription',
        'invoice'  => 'invoice',
        'dispute'  => 'dispute',
        'refund'   => 'refund',
        'customer' => 'customer',
    ];

    /**
     * Shapes read on purpose that the pinned version does not carry, with the
     * reason. A webhook endpoint with no api_version of its own renders at the
     * account default, which on a recent account is newer than the pin, so the
     * gateway reads the flat field first and falls back to the nested one.
     *
     * Each entry has to say why, so that adding one is a decision rather than a
     * way of quieting this test.
     */
    private const TOLERATED = [
        // Moved out of the top level in 2025-03-31.basil; read only after the
        // pinned flat field comes back empty. See invoiceRefs().
        'invoice.parent'   => 'basil moved subscription under parent.subscription_details',
        'invoice.payments' => 'basil moved payment_intent under payments.data[].payment',
        // Removed from PaymentIntent in 2022-11-15, replaced by latest_charge,
        // which is read first. Harmless, and it costs nothing to keep.
        'payment_intent.charges' => 'pre-2022 shape, read only as a fallback behind latest_charge',
    ];

    /** @return array<string,mixed> */
    private function schema(): array
    {
        $path = dirname(__DIR__) . '/fixtures/stripe-acacia-schema.json';
        $this->assertFileExists($path);

        return (array) json_decode((string) file_get_contents($path), true);
    }

    /** @return array<string,string> file path => source */
    private function gatewaySources(): array
    {
        $out = [];
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Gateways/Stripe/*.php') as $file) {
            $out[(string) $file] = (string) file_get_contents((string) $file);
        }
        $this->assertNotEmpty($out, 'no Stripe gateway sources found to check');

        return $out;
    }

    public function test_the_extract_is_the_version_the_client_pins(): void
    {
        $client = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Gateways/Stripe/StripeApi.php');
        $this->assertSame(
            1,
            preg_match("/API_VERSION\s*=\s*'([^']+)'/", $client, $m),
            'StripeApi no longer declares an API_VERSION this can be checked against'
        );

        $this->assertSame(
            $m[1],
            (string) ($this->schema()['version'] ?? ''),
            'the pinned API version moved, so the schema extract beside this test is for a different API'
        );
    }

    public function test_every_field_read_off_a_stripe_response_exists_on_it(): void
    {
        $objects = (array) $this->schema()['objects'];
        $unknown = [];

        foreach ($this->gatewaySources() as $file => $src) {
            foreach (preg_split('/\R/', $src) ?: [] as $n => $line) {
                foreach (self::RESPONSE_VARS as $var => $object) {
                    preg_match_all('/\$' . $var . "\[\s*'([a-z_]+)'\s*\]/", $line, $hits);
                    foreach ($hits[1] as $field) {
                        if (in_array($field, (array) $objects[$object]['properties'], true)) {
                            continue;
                        }
                        if (isset(self::TOLERATED[$object . '.' . $field])) {
                            continue;
                        }
                        $unknown[] = sprintf(
                            '%s:%d reads $%s[\'%s\'], which is not on `%s`',
                            basename((string) $file),
                            $n + 1,
                            $var,
                            $field,
                            $object
                        );
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unknown)), implode("\n", array_unique($unknown)));
    }

    public function test_every_expand_asked_for_is_one_the_object_answers_to(): void
    {
        $objects = (array) $this->schema()['objects'];

        // Which resource path belongs to which object, for the expands below.
        $resource = [
            '/charges/'         => 'charge',
            '/payment_intents/' => 'payment_intent',
            '/subscriptions/'   => 'subscription',
            '/invoices/'        => 'invoice',
            '/disputes/'        => 'dispute',
            '/customers/'       => 'customer',
        ];

        $bad = [];
        foreach ($this->gatewaySources() as $file => $src) {
            foreach (preg_split('/\R/', $src) ?: [] as $n => $line) {
                if (! str_contains($line, 'expand[]')) {
                    continue;
                }

                preg_match_all("/expand\[\]=([a-z_.]+)/", $line, $asked);
                foreach ($resource as $path => $object) {
                    if (! str_contains($line, $path)) {
                        continue;
                    }
                    foreach ($asked[1] as $field) {
                        // Only the first segment: a nested expand is checked
                        // against the object the request is for.
                        $head = explode('.', $field)[0];
                        if (in_array($head, (array) $objects[$object]['expandable'], true)) {
                            continue;
                        }
                        $bad[] = sprintf(
                            '%s:%d asks to expand `%s` on `%s`, which refuses it and fails the whole request',
                            basename((string) $file),
                            $n + 1,
                            $field,
                            $object
                        );
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($bad)), implode("\n", array_unique($bad)));
    }

    public function test_every_status_branched_on_is_one_stripe_emits(): void
    {
        $objects = (array) $this->schema()['objects'];
        $src     = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Gateways/Stripe/StripeGateway.php');

        // The dispute statuses the gateway treats as money the org still holds.
        $this->assertSame(1, preg_match('/DISPUTE_FUNDS_HELD_BY_ORG = \[(.*?)\];/s', $src, $m));
        preg_match_all("/'([a-z_]+)'/", $m[1], $held);

        $real = (array) $objects['dispute']['enums']['status'];
        foreach ($held[1] as $status) {
            $this->assertContains(
                $status,
                $real,
                "DISPUTE_FUNDS_HELD_BY_ORG names `{$status}`, which Stripe never sets, so that branch is dead"
            );
        }
    }
}
