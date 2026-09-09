<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Auth\Capabilities;

/**
 * Add-ons register their capabilities through the gratora.capabilities filter:
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
            $maps['all'][]                     = 'gratora_qa_cap';
            $maps['groups']['QA']              = ['gratora_qa_cap'];
            $maps['labels']['gratora_qa_cap']     = 'QA capability';
            return $maps;
        };
        add_filter('gratora.capabilities', $this->filter);
    }

    protected function tearDown(): void
    {
        remove_filter('gratora.capabilities', $this->filter);
        parent::tearDown();
    }

    public function test_filtered_caps_appear_in_the_maps(): void
    {
        $this->assertContains('gratora_qa_cap', Capabilities::all());
        $this->assertSame(['gratora_qa_cap'], Capabilities::groups()['QA'] ?? null);
        $this->assertSame('QA capability', Capabilities::labels()['gratora_qa_cap'] ?? null);
        // Core caps survive the merge.
        $this->assertContains('gratora_view_donations', Capabilities::all());
    }

    public function test_apply_mapping_grants_and_revokes_a_filtered_cap(): void
    {
        Capabilities::applyMapping(['subscriber' => ['gratora_qa_cap']]);
        $role = get_role('subscriber');
        $this->assertTrue($role->has_cap('gratora_qa_cap'), 'filtered cap granted');
        $this->assertTrue($role->has_cap(Capabilities::MANAGE), 'any grant carries the umbrella');

        Capabilities::applyMapping(['subscriber' => []]);
        $role = get_role('subscriber');
        $this->assertFalse($role->has_cap('gratora_qa_cap'), 'filtered cap revoked');
    }

    /**
     * Add-ons declare the everyday caps their command packs need through
     * gratora.capabilities.admin_caps; a default administrator then holds them so
     * the assistant (strict granular dispatch) can drive add-on commands.
     */
    public function test_admin_caps_filter_grants_super_admins_the_declared_cap(): void
    {
        $cb = static fn (array $caps): array => array_merge($caps, ['gratora_qa_admin_cap']);
        add_filter('gratora.capabilities.admin_caps', $cb);

        $super = Capabilities::grantMetaCaps(['manage_options' => true]);
        $this->assertTrue($super['gratora_qa_admin_cap'] ?? false, 'super-admin implicitly holds the declared add-on cap');

        // A scoped role that is not a super-admin does not gain it implicitly.
        $plain = Capabilities::grantMetaCaps(['gratora_view_donations' => true]);
        $this->assertArrayNotHasKey('gratora_qa_admin_cap', $plain);

        remove_filter('gratora.capabilities.admin_caps', $cb);
    }
}
