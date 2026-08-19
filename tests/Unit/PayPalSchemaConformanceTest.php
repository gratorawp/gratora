<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The PayPal gateway read against PayPal's own schemas.
 *
 * Same reasoning as the Stripe conformance test beside it: every suite that
 * exercises this gateway answers PayPal from a canned array, so a field the API
 * does not return reads back exactly as well as one it does, and a status PayPal
 * never sets makes a branch that can never fire. The Stripe integration had both
 * and passed every test.
 *
 * The fixture is an extract of PayPal's published OpenAPI specifications, with
 * the $refs resolved, so it is the one source here that does not agree with the
 * code by construction. Where the read paths are nested, the nesting is checked
 * too, since that is what a canned fixture most easily gets wrong.
 *
 * A capture arriving on a webhook carries more than the Orders API returns for
 * one, so supplementary_data is folded in from the Payments specification.
 *
 * This is a static read of the source and no substitute for a donation put
 * through a real sandbox account. What it closes is the case where the code and
 * its fixtures agree with each other about a shape PayPal never returns.
 */
final class PayPalSchemaConformanceTest extends TestCase
{
    /**
     * Every nested path this gateway reads off a PayPal response, and the object
     * it reads it from. Kept explicit rather than scraped, because these are
     * reached through nesting a regex cannot follow reliably, and because the
     * list is short enough that stating it is clearer than deriving it.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function readPaths(): array
    {
        return [
            ['order', 'id'],
            ['order', 'status'],
            ['order', 'payer.email_address'],
            ['order', 'purchase_units.[].payments.captures'],

            ['capture', 'id'],
            ['capture', 'status'],
            ['capture', 'custom_id'],
            ['capture', 'amount.value'],
            ['capture', 'amount.currency_code'],
            ['capture', 'status_details.reason'],
            ['capture', 'seller_receivable_breakdown.paypal_fee.value'],
            ['capture', 'seller_receivable_breakdown.paypal_fee.currency_code'],
            ['capture', 'supplementary_data.related_ids.order_id'],

            ['subscription', 'id'],
            ['subscription', 'status'],
            ['subscription', 'custom_id'],
            ['subscription', 'plan_id'],
            ['subscription', 'subscriber.payer_id'],
            ['subscription', 'billing_info.next_billing_time'],
        ];
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        $path = dirname(__DIR__) . '/fixtures/paypal-schema.json';
        $this->assertFileExists($path);

        return (array) json_decode((string) file_get_contents($path), true);
    }

    private function source(): string
    {
        $src = '';
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Gateways/PayPal/*.php') as $file) {
            $src .= (string) file_get_contents((string) $file);
        }
        $this->assertNotSame('', $src, 'no PayPal gateway sources found to check');

        return $src;
    }

    /**
     * @dataProvider readPaths
     */
    public function test_every_field_read_off_a_paypal_response_exists_on_it(string $object, string $path): void
    {
        $node = (array) $this->schema()['objects'][$object]['tree'];

        foreach (explode('.', $path) as $segment) {
            $this->assertArrayHasKey(
                $segment,
                $node,
                "`{$object}.{$path}` reads `{$segment}`, which PayPal's schema does not have at that position"
            );

            $next = $node[$segment];
            // true is a leaf, or a node the extract stopped resolving at.
            $node = is_array($next) ? $next : [];
        }

        $this->addToAssertionCount(1);
    }

    /**
     * A status the API never sets is a branch that can never be taken, which no
     * fixture-driven test can tell apart from one that simply was not reached.
     */
    public function test_every_status_branched_on_is_one_paypal_sets(): void
    {
        $objects = (array) $this->schema()['objects'];
        $src     = $this->source();

        // A resource status, not an error code: PayPal's error vocabulary
        // (details[].issue, and the error name above it) is separate, wider than
        // these specifications document, and read through hasIssue() rather than
        // compared to a status field. Conflating the two would flag correct code.
        $known = array_merge(
            (array) $objects['order']['status'],
            (array) $objects['capture']['status'],
            (array) $objects['subscription']['status'],
            // The signature check, whose response is a status of its own.
            // notifications_webhooks_v1 declares SUCCESS and FAILURE.
            ['SUCCESS', 'FAILURE']
        );

        // The literal is almost never on the line that reads the field: the
        // status goes into a variable first. So find the variables assigned
        // from a `status` read, then read the lines that compare them. Scanning
        // only the reading line finds nothing at all and passes for that reason.
        preg_match_all("/\\$([a-zA-Z_]+)\s*=\s*\(string\)\s*\(\\$[a-zA-Z_]+\[\s*'status'\s*\]/", $src, $assigned);
        $vars = array_values(array_unique($assigned[1]));
        $this->assertNotSame([], $vars, 'no status variable found, so this test would check nothing');

        $lines = preg_split('/\R/', $src) ?: [];
        $unknown = [];
        foreach ($lines as $line) {
            $reads = false;
            foreach ($vars as $var) {
                if (preg_match('/\$' . $var . '\b/', $line) === 1) {
                    $reads = true;
                    break;
                }
            }
            // isAlreadyInThatState takes the states in which the call had
            // nothing left to do, which are subscription statuses.
            $reads = $reads || str_contains($line, 'isAlreadyInThatState(');
            if (! $reads) {
                continue;
            }

            preg_match_all("/'([A-Z][A-Z_]{3,})'/", $line, $literals);
            foreach ($literals[1] as $literal) {
                if (! in_array($literal, $known, true)) {
                    $unknown[] = $literal;
                }
            }
        }

        $unknown = array_values(array_unique($unknown));
        $this->assertSame(
            [],
            $unknown,
            'compared against a status PayPal never sets: ' . implode(', ', $unknown)
        );
    }

    public function test_the_extract_names_the_specifications_it_came_from(): void
    {
        $schema = $this->schema();

        $this->assertStringContainsString('paypal', (string) ($schema['source'] ?? ''));
        $this->assertNotEmpty($schema['specs'] ?? [], 'the extract does not say which specifications it is from');
    }
}
