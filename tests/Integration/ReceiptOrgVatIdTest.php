<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Receipts\ReceiptContext;
use WP_REST_Request;

/**
 * The Organization screen takes an EU VAT ID from any org in a VAT country and
 * saved it into a field nothing in the product read: not the receipt, not the
 * donor's annual statement, not an export, and nothing in any add-on either.
 * It belongs beside the tax ID it sits next to on screen.
 */
final class ReceiptOrgVatIdTest extends IntegrationTestCase
{
    /** The org profile the receipt pipeline was handed, through the real hook. */
    private function orgOnTheReceipt(): array
    {
        $seen = null;
        add_filter('gratora.receipt.context', static function (ReceiptContext $ctx) use (&$seen) {
            $seen = $ctx->org;
            return $ctx;
        });

        $this->driveDonationToPaid();
        $this->runPendingAsyncJobs();

        $this->assertIsArray($seen, 'the receipt pipeline ran');

        return $seen;
    }

    public function test_the_vat_id_an_org_saved_reaches_the_receipt(): void
    {
        update_option('gratora_org_profile', [
            'legal_name'    => 'Helping Hands Foundation e.V.',
            'address_lines' => ['1 Market Street'],
            'tax_id'        => 'DE-CHARITY-99',
            'vat_id'        => 'DE123456789',
            'email'         => 'hello@example.org',
        ], false);

        $org = $this->orgOnTheReceipt();

        $this->assertSame('DE123456789', $org['vat_id']);
        $this->assertSame('DE-CHARITY-99', $org['tax_id'], 'and it did not displace the tax ID');
    }

    public function test_an_org_with_no_vat_id_carries_an_empty_one_rather_than_nothing(): void
    {
        update_option('gratora_org_profile', [
            'legal_name'    => 'Helping Hands',
            'address_lines' => ['1 Market Street'],
            'email'         => 'hello@example.org',
        ], false);

        $this->assertSame('', $this->orgOnTheReceipt()['vat_id'], 'the template can print it unguarded');
    }

    private function driveDonationToPaid(): string
    {
        $create = new WP_REST_Request('POST', '/gratora/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'vat-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Doe', 'country' => 'DE'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/gratora/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }

    /**
     * The panel writes one array index per input, so skipping the middle one
     * stores a hole. A hole prints as an empty line between the street and the
     * city on every receipt and statement the org sends.
     */
    public function test_a_skipped_address_line_is_not_printed_as_a_blank_line(): void
    {
        update_option('gratora_org_profile', [
            'legal_name'    => 'Helping Hands',
            'address_lines' => ['Kirchweg 3', null, 'Berlin'],
            'email'         => 'hello@example.org',
        ], false);

        $this->assertSame(['Kirchweg 3', 'Berlin'], $this->orgOnTheReceipt()['address_lines']);
    }

    public function test_an_org_with_no_address_still_gets_a_list(): void
    {
        update_option('gratora_org_profile', [
            'legal_name' => 'Helping Hands',
            'email'      => 'hello@example.org',
        ], false);

        $this->assertSame([], $this->orgOnTheReceipt()['address_lines']);
    }
}
