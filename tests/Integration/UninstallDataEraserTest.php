<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Core\Activator;
use Dono\Core\CoreModule;
use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\Plugin;
use Dono\Foundation\Uninstall\DataEraser;

/**
 * The one feature whose bug costs a charity its donation history.
 *
 * Nothing here calls erase(). It would drop the tables of the shared test
 * database and every later test in the run would fail against the wreckage, so
 * what is asserted is the plan: the opt-in that gates it, and the exact set of
 * tables and options it would take. The add-ons share the dono_ prefix, so a
 * wipe that matched on it would destroy the tickets, gift aid and
 * peer-to-peer data of plugins that are still installed.
 */
final class UninstallDataEraserTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option(DataEraser::OPT_IN);
        parent::tearDown();
    }

    /** The opt-in is the whole safety catch, so it must not be truthy by default. */
    public function test_a_fresh_site_has_not_opted_in(): void
    {
        delete_option(DataEraser::OPT_IN);

        $this->assertFalse(DataEraser::requested());
    }

    public function test_the_opt_in_is_read_once_set(): void
    {
        update_option(DataEraser::OPT_IN, true, false);

        $this->assertTrue(DataEraser::requested());
    }

    /**
     * The wipe runs on deactivation, so a flag that outlived it is a wipe
     * waiting to fire on a site that has since changed its mind.
     */
    public function test_reactivating_withdraws_a_pending_wipe(): void
    {
        update_option(DataEraser::OPT_IN, true, false);

        Plugin::instance()->container->get(Activator::class)->activate();

        $this->assertFalse(DataEraser::requested());
    }

    /**
     * The page ids live in the campaigns table, so they have to be read before
     * it is dropped. Read after, every campaign page is left behind.
     */
    public function test_page_ids_are_readable_before_anything_is_dropped(): void
    {
        $req = new \WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Erase probe', 'status' => 'published']));
        $created = rest_do_request($req)->get_data();

        $pageId = (int) ($created['page_id'] ?? 0);
        $this->assertGreaterThan(0, $pageId, 'precondition: the campaign has a page');

        $this->assertContains($pageId, (new DataEraser())->pageIds());
    }

    public function test_only_tables_core_owns_are_planned(): void
    {
        $tables = (new DataEraser())->plan()['tables'];

        $this->assertContains('dono_donations', $tables);
        $this->assertContains('dono_donors', $tables);
        $this->assertContains('dono_system_settings', $tables);

        // Each of these exists on a site running the add-ons.
        foreach ([
            'dono_ticket_orders',
            'dono_ticket_events',
            'dono_event_attendees',
            'dono_fundraisers',
            'dono_fundraiser_teams',
            'dono_p2p_sponsors',
            'dono_gift_aid_claims',
            'dono_gift_aid_declarations',
            'dono_ai_conversations',
            'dono_connect_events',
            'dono_donation_tributes',
            'dono_give_import_map',
        ] as $foreign) {
            $this->assertNotContains(
                $foreign,
                $tables,
                "{$foreign} belongs to an add-on and must never be planned by core"
            );
        }
    }

    /** The list tracks the module rather than a hand-maintained copy of it. */
    public function test_the_table_plan_is_derived_from_the_module(): void
    {
        $tables = (new DataEraser())->plan()['tables'];

        $this->assertCount(count((new CoreModule())->migrations()), $tables);
        $this->assertSame(array_values(array_unique($tables)), $tables, 'no duplicates');
    }

    public function test_only_options_core_owns_are_planned(): void
    {
        update_option('dono_gift_aid_db_version', '9.9.9', false);
        update_option('dono_p2p_rules_version', '1', false);

        $options = (new DataEraser())->plan()['options'];

        $this->assertContains('dono_org_profile', $options);
        $this->assertContains('dono_db_version', $options);
        $this->assertNotContains('dono_gift_aid_db_version', $options);
        $this->assertNotContains('dono_p2p_rules_version', $options);

        delete_option('dono_gift_aid_db_version');
        delete_option('dono_p2p_rules_version');
    }

    /** Reference counters carry the year, so they are matched rather than listed. */
    public function test_reference_counters_are_planned_whatever_year_they_name(): void
    {
        update_option('dono_reference_counter_donation_2031', 7, false);

        $this->assertContains('dono_reference_counter_donation_2031', (new DataEraser())->plan()['options']);

        delete_option('dono_reference_counter_donation_2031');
    }

    /** The opt-in itself goes, so a reinstall does not inherit a standing wipe. */
    public function test_the_opt_in_erases_itself(): void
    {
        $this->assertContains(DataEraser::OPT_IN, (new DataEraser())->plan()['options']);
    }

    /**
     * An add-on's capabilities are its own to remove. dono_manage_fundraisers
     * is registered by the peer-to-peer plugin, and taking it here would break
     * a site that keeps that plugin.
     */
    public function test_only_core_capabilities_are_named(): void
    {
        $caps = [...Capabilities::ALL, Capabilities::MANAGE];

        foreach (['dono_manage_fundraisers', 'dono_manage_connect'] as $foreign) {
            $this->assertNotContains($foreign, $caps, "{$foreign} belongs to an add-on");
        }
        $this->assertContains('dono_view_donations', $caps);
    }

    public function test_planning_reads_nothing_destructive(): void
    {
        (new DataEraser())->plan();
        (new DataEraser())->plan();

        $this->assertNotEmpty(get_option('dono_org_profile', []), 'planning must not delete anything');
    }
}
