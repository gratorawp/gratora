<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Auth\Capabilities;

/**
 * Add-ons register their capabilities through the dono.capabilities filter:
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
            $maps['all'][]                     = 'dono_qa_cap';
            $maps['groups']['QA']              = ['dono_qa_cap'];
            $maps['labels']['dono_qa_cap']     = 'QA capability';
            return $maps;
        };
        add_filter('dono.capabilities', $this->filter);
    }

    protected function tearDown(): void
    {
        remove_filter('dono.capabilities', $this->filter);
        parent::tearDown();
    }

    public function test_filtered_caps_appear_in_the_maps(): void
    {
        $this->assertContains('dono_qa_cap', Capabilities::all());
        $this->assertSame(['dono_qa_cap'], Capabilities::groups()['QA'] ?? null);
        $this->assertSame('QA capability', Capabilities::labels()['dono_qa_cap'] ?? null);
        // Core caps survive the merge.
        $this->assertContains('dono_view_donations', Capabilities::all());
    }

    public function test_apply_mapping_grants_and_revokes_a_filtered_cap(): void
    {
        Capabilities::applyMapping(['subscriber' => ['dono_qa_cap']]);
        $role = get_role('subscriber');
        $this->assertTrue($role->has_cap('dono_qa_cap'), 'filtered cap granted');
        $this->assertTrue($role->has_cap(Capabilities::MANAGE), 'any grant carries the umbrella');

        Capabilities::applyMapping(['subscriber' => []]);
        $role = get_role('subscriber');
        $this->assertFalse($role->has_cap('dono_qa_cap'), 'filtered cap revoked');
    }

    /**
     * Add-ons declare the everyday caps their command packs need through
     * dono.capabilities.admin_caps; a default administrator then holds them so
     * the assistant (strict granular dispatch) can drive add-on commands.
     */
    public function test_admin_caps_filter_grants_super_admins_the_declared_cap(): void
    {
        $cb = static fn (array $caps): array => array_merge($caps, ['dono_qa_admin_cap']);
        add_filter('dono.capabilities.admin_caps', $cb);

        $super = Capabilities::grantMetaCaps(['manage_options' => true]);
        $this->assertTrue($super['dono_qa_admin_cap'] ?? false, 'super-admin implicitly holds the declared add-on cap');

        // A scoped role that is not a super-admin does not gain it implicitly.
        $plain = Capabilities::grantMetaCaps(['dono_view_donations' => true]);
        $this->assertArrayNotHasKey('dono_qa_admin_cap', $plain);

        remove_filter('dono.capabilities.admin_caps', $cb);
    }
}
