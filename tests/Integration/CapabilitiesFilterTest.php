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
}
