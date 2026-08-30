<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Receipts\ReceiptContext;
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
        add_filter('fundkit.receipt.context', static function (ReceiptContext $ctx) use (&$seen) {
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
        update_option('fundkit_org_profile', [
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
        update_option('fundkit_org_profile', [
            'legal_name'    => 'Helping Hands',
            'address_lines' => ['1 Market Street'],
            'email'         => 'hello@example.org',
        ], false);

        $this->assertSame('', $this->orgOnTheReceipt()['vat_id'], 'the template can print it unguarded');
    }

    private function driveDonationToPaid(): string
    {
        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'vat-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Doe', 'country' => 'DE'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/fundkit/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }
}
