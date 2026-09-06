<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Receipts\OrgProfile;
use FundKit\Reports\TaxStatementBuilder;
use FundKit\Foundation\Plugin;
use ReflectionMethod;

/**
 * Onboarding asks for a country and, for the US, a state, and asks for neither
 * street nor city. Composing an address out of what it wrote puts "CA" and "US"
 * on the masthead of a tax document, while the readiness check, which reads
 * address_lines, tells the org there is no address at all.
 */
final class StatementOrgAddressTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option('fundkit_org_profile');
        parent::tearDown();
    }

    /** @return list<string> */
    private function addressLines(): array
    {
        $m = new ReflectionMethod(TaxStatementBuilder::class, 'orgAddressLines');
        $m->setAccessible(true);

        return (array) $m->invoke(
            Plugin::instance()->container->get(TaxStatementBuilder::class),
            OrgProfile::load()
        );
    }

    public function test_a_state_and_a_country_code_are_not_an_address(): void
    {
        update_option('fundkit_org_profile', [
            'legal_name' => 'Helping Hands',
            'state'      => 'CA',
            'country'    => 'US',
        ], false);

        $this->assertSame([], $this->addressLines());
    }

    public function test_a_postcode_alone_is_not_one_either(): void
    {
        update_option('fundkit_org_profile', [
            'legal_name'  => 'Helping Hands',
            'postal_code' => '94110',
            'country'     => 'US',
        ], false);

        $this->assertSame([], $this->addressLines());
    }

    /** A real address still prints, region line and all. */
    public function test_an_address_the_org_actually_gave_is_kept(): void
    {
        update_option('fundkit_org_profile', [
            'legal_name'    => 'Helping Hands',
            'address_line1' => '1 Market Street',
            'city'          => 'San Francisco',
            'state'         => 'CA',
            'postal_code'   => '94110',
            'country'       => 'US',
        ], false);

        $lines = $this->addressLines();

        $this->assertContains('1 Market Street', $lines);
        $this->assertContains('San Francisco, CA 94110', $lines);
    }

    /** And so does a city with no street, which is an address a reader can use. */
    public function test_a_city_alone_still_counts(): void
    {
        update_option('fundkit_org_profile', [
            'legal_name' => 'Helping Hands',
            'city'       => 'Berlin',
            'country'    => 'DE',
        ], false);

        $lines = $this->addressLines();

        $this->assertContains('Berlin', $lines);
    }
}
