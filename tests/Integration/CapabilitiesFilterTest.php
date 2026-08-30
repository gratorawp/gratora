<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Auth\Capabilities;

/**
 * Add-ons register their capabilities through the fundkit.capabilities filter:
 * the filtered maps drive the roles screen and applyMapping, so an add-on cap
 * is grantable and revocable exactly like a core one.
 */
final class CapabilitiesFilterTest extends IntegrationTestCase
{
    private $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = static function (array $maps): array {
            $maps['all'][]                     = 'fundkit_qa_cap';
            $maps['groups']['QA']              = ['fundkit_qa_cap'];
            $maps['labels']['fundkit_qa_cap']     = 'QA capability';
            return $maps;
        };
        add_filter('fundkit.capabilities', $this->filter);
    }

    protected function tearDown(): void
    {
        remove_filter('fundkit.capabilities', $this->filter);
        parent::tearDown();
    }

    public function test_filtered_caps_appear_in_the_maps(): void
    {
        $this->assertContains('fundkit_qa_cap', Capabilities::all());
        $this->assertSame(['fundkit_qa_cap'], Capabilities::groups()['QA'] ?? null);
        $this->assertSame('QA capability', Capabilities::labels()['fundkit_qa_cap'] ?? null);
        // Core caps survive the merge.
        $this->assertContains('fundkit_view_donations', Capabilities::all());
    }

    public function test_apply_mapping_grants_and_revokes_a_filtered_cap(): void
    {
        Capabilities::applyMapping(['subscriber' => ['fundkit_qa_cap']]);
        $role = get_role('subscriber');
        $this->assertTrue($role->has_cap('fundkit_qa_cap'), 'filtered cap granted');
        $this->assertTrue($role->has_cap(Capabilities::MANAGE), 'any grant carries the umbrella');

        Capabilities::applyMapping(['subscriber' => []]);
        $role = get_role('subscriber');
        $this->assertFalse($role->has_cap('fundkit_qa_cap'), 'filtered cap revoked');
    }

    /**
     * Add-ons declare the everyday caps their command packs need through
     * fundkit.capabilities.admin_caps; a default administrator then holds them so
     * the assistant (strict granular dispatch) can drive add-on commands.
     */
    public function test_admin_caps_filter_grants_super_admins_the_declared_cap(): void
    {
        $cb = static fn (array $caps): array => array_merge($caps, ['fundkit_qa_admin_cap']);
        add_filter('fundkit.capabilities.admin_caps', $cb);

        $super = Capabilities::grantMetaCaps(['manage_options' => true]);
        $this->assertTrue($super['fundkit_qa_admin_cap'] ?? false, 'super-admin implicitly holds the declared add-on cap');

        // A scoped role that is not a super-admin does not gain it implicitly.
        $plain = Capabilities::grantMetaCaps(['fundkit_view_donations' => true]);
        $this->assertArrayNotHasKey('fundkit_qa_admin_cap', $plain);

        remove_filter('fundkit.capabilities.admin_caps', $cb);
    }
}
