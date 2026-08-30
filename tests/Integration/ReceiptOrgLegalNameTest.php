<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Receipts\ReceiptContext;
use WP_REST_Request;

/**
 * Legal name is the one required field on the Organization screen, and Setup
 * reports "Receipts carry your name and address" as soon as it is filled in.
 * The receipt printed the optional display name, so an org that answered the
 * question it was asked issued documents from an empty string.
 */
final class ReceiptOrgLegalNameTest extends IntegrationTestCase
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

    public function test_a_receipt_carries_the_legal_name_when_there_is_no_display_name(): void
    {
        update_option('fundkit_org_profile', [
            'name'          => '',
            'legal_name'    => 'Helping Hands Foundation e.V.',
            'address_lines' => ['1 Market Street'],
            'email'         => 'hello@example.org',
        ], false);

        $this->assertSame('Helping Hands Foundation e.V.', $this->orgOnTheReceipt()['name']);
    }

    public function test_a_display_name_still_wins_where_the_org_set_one(): void
    {
        update_option('fundkit_org_profile', [
            'name'       => 'Helping Hands',
            'legal_name' => 'Helping Hands Foundation e.V.',
        ], false);

        $this->assertSame('Helping Hands', $this->orgOnTheReceipt()['name']);
    }

    public function test_an_org_that_filled_in_neither_falls_back_to_the_site(): void
    {
        update_option('fundkit_org_profile', ['name' => '', 'legal_name' => ''], false);

        $this->assertSame(get_bloginfo('name'), $this->orgOnTheReceipt()['name']);
    }

    /**
     * The receipt in the donor's inbox links to the download route, which
     * re-renders from its own copy of the organisation. That copy is the one
     * the donor opens, so the name has to resolve there too.
     */
    public function test_the_receipt_the_donor_downloads_carries_the_legal_name(): void
    {
        update_option('fundkit_org_profile', [
            'name'          => '',
            'legal_name'    => 'Helping Hands Foundation e.V.',
            'address_lines' => ['1 Market Street'],
            'email'         => 'hello@example.org',
        ], false);

        $ref = new \ReflectionMethod(\FundKit\Rest\ReceiptsController::class, 'loadOrgProfile');
        $ref->setAccessible(true);
        $org = $ref->invoke(
            \FundKit\Foundation\Plugin::instance()->container->get(\FundKit\Rest\ReceiptsController::class)
        );

        $this->assertSame('Helping Hands Foundation e.V.', $org['name'], 'the downloaded receipt is headed by nobody');
    }

    /** The yearly summary a donor keeps for tax is the same promise. */
    public function test_the_annual_statement_carries_the_legal_name(): void
    {
        update_option('fundkit_org_profile', [
            'name'       => '',
            'legal_name' => 'Helping Hands Foundation e.V.',
        ], false);

        $this->assertSame('Helping Hands Foundation e.V.', \FundKit\Receipts\OrgProfile::load()['name']);
    }

    private function driveDonationToPaid(): string
    {
        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Doe', 'country' => 'US'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/fundkit/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);

        return $reference;
    }
}
