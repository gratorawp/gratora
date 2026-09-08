<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Core\Activator;
use FundKit\Core\CoreModule;
use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Uninstall\DataEraser;

/**
 * Inspect the wipe plan without calling erase(), which would destroy the shared test database.
 * Preserve installed add-ons’ tables.
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
        // The dialog records when the answer was given, not merely that it was:
        // an answer nobody acted on has to expire rather than wait for some
        // later deactivation to find it.
        update_option(DataEraser::OPT_IN, time(), false);

        $this->assertTrue(DataEraser::requested());
    }

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
        $req = new \WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
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

        $this->assertContains('fundkit_donations', $tables);
        $this->assertContains('fundkit_donors', $tables);
        $this->assertContains('fundkit_system_settings', $tables);

        // Each of these exists on a site running the add-ons.
        foreach ([
            'fundkit_ticket_orders',
            'fundkit_ticket_events',
            'fundkit_event_attendees',
            'fundkit_fundraisers',
            'fundkit_fundraiser_teams',
            'fundkit_p2p_sponsors',
            'fundkit_gift_aid_claims',
            'fundkit_gift_aid_declarations',
            'fundkit_ai_conversations',
            'fundkit_connect_events',
            'fundkit_donation_tributes',
            'fundkit_give_import_map',
        ] as $foreign) {
            $this->assertNotContains(
                $foreign,
                $tables,
                "{$foreign} belongs to an add-on and must never be planned by core"
            );
        }
    }

    public function test_the_table_plan_is_derived_from_the_module(): void
    {
        $tables = (new DataEraser())->plan()['tables'];

        $this->assertCount(count((new CoreModule())->migrations()), $tables);
        $this->assertSame(array_values(array_unique($tables)), $tables, 'no duplicates');
    }

    public function test_only_options_core_owns_are_planned(): void
    {
        update_option('fundkit_gift_aid_db_version', '9.9.9', false);
        update_option('fundkit_p2p_rules_version', '1', false);

        $options = (new DataEraser())->plan()['options'];

        $this->assertContains('fundkit_org_profile', $options);
        $this->assertContains('fundkit_db_version', $options);
        $this->assertNotContains('fundkit_gift_aid_db_version', $options);
        $this->assertNotContains('fundkit_p2p_rules_version', $options);

        delete_option('fundkit_gift_aid_db_version');
        delete_option('fundkit_p2p_rules_version');
    }

    /**
     * Settings a site can see it saved are the obvious half. The other half is
     * the work core queued for itself: those maps are keyed by row id, so one
     * surviving the wipe is an instruction a reinstall will carry out against
     * whichever campaign or fund inherits the id. Opening Campaigns would
     * cancel live recurring plans at the gateway.
     */
    public function test_every_option_core_writes_is_planned(): void
    {
        $options = (new DataEraser())->plan()['options'];

        foreach ([
            'fundkit_campaign_cancel_recurring',
            'fundkit_fund_reassignments',
            'fundkit_donor_rehash_pending',
            'fundkit_donor_rehash_after_id',
            'fundkit_retention_starts_at',
            'fundkit_retention_cursor',
            'fundkit_gateway_reconcile_cursor',
            'fundkit_upgrade_routines_failed',
            'fundkit_consents',
            'fundkit_email_settings',
            'fundkit_paypal_product',
            'fundkit_paypal_plans',
        ] as $option) {
            $this->assertContains(
                $option,
                $options,
                "{$option} is written by core and must not outlive an opt-in wipe"
            );
        }
    }

    /**
     * These two names belong to fundkit/fundkit-licensing, which every paid
     * add-on vendors and which has no uninstall of its own. Core is the only
     * thing that erases them, so a name that drifts apart from the client's
     * leaves the licence key, a bearer credential, on the site after uninstall.
     *
     * The literals are LicenseStore::KEY_OPTION and ::STATUS_OPTION at ^2.0.
     * They are spelled out because core does not depend on that package and so
     * cannot read the constants.
     */
    public function test_the_licensing_client_options_are_planned(): void
    {
        $options = (new DataEraser())->plan()['options'];

        $this->assertContains(
            'fundkit_pro_license_key',
            $options,
            'the licence key must not outlive an opt-in wipe'
        );
        $this->assertContains('fundkit_licensing_status', $options);
    }

    public function test_reference_counters_are_planned_whatever_year_they_name(): void
    {
        update_option('fundkit_reference_counter_donation_2031', 7, false);

        $this->assertContains('fundkit_reference_counter_donation_2031', (new DataEraser())->plan()['options']);

        delete_option('fundkit_reference_counter_donation_2031');
    }

    public function test_the_opt_in_erases_itself(): void
    {
        $this->assertContains(DataEraser::OPT_IN, (new DataEraser())->plan()['options']);
    }

    public function test_only_core_capabilities_are_named(): void
    {
        $caps = [...Capabilities::ALL, Capabilities::MANAGE];

        foreach (['fundkit_manage_fundraisers', 'fundkit_manage_connect'] as $foreign) {
            $this->assertNotContains($foreign, $caps, "{$foreign} belongs to an add-on");
        }
        $this->assertContains('fundkit_view_donations', $caps);
    }

    public function test_planning_reads_nothing_destructive(): void
    {
        update_option('fundkit_org_profile', ['name' => 'Acme Foundation'], false);

        (new DataEraser())->plan();
        (new DataEraser())->plan();

        $this->assertNotEmpty(get_option('fundkit_org_profile', []), 'planning must not delete anything');
    }
}
