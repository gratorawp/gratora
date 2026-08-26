<?php

declare(strict_types=1);

namespace GiveFlow\Foundation\Uninstall;

use GiveFlow\Campaigns\Campaign;
use GiveFlow\Core\CoreModule;
use GiveFlow\Donors\Donor;
use GiveFlow\Foundation\Auth\Capabilities;
use ReflectionClass;

/**
 * Removes everything core owns, when the site owner has asked for it.
 *
 * Tables come from CoreModule::migrations() rather than a `giveflow_%` glob. The
 * add-ons share that prefix, so a glob run from core would drop the tickets,
 * gift aid and peer-to-peer tables of add-ons that are still installed.
 *
 * @since 1.0.0
 */
final class DataEraser
{
    public const OPT_IN = 'giveflow_delete_data';

    /**
     * Options core writes. Listed rather than matched on a prefix for the same
     * reason as the tables: giveflow_gift_aid_db_version and its siblings belong to
     * other plugins.
     *
     * The queued-work maps and cursors belong here as much as the settings do.
     * They are keyed by row id, and a reinstall hands those ids back out, so
     * one left behind is an instruction aimed at whatever now holds the id.
     */
    private const OPTIONS = [
        'giveflow_activated_at',
        'giveflow_campaign_cancel_recurring',
        'giveflow_consents',
        'giveflow_currency_locale',
        'giveflow_db_version',
        'giveflow_delete_data',
        'giveflow_donor_rehash_after_id',
        'giveflow_donor_rehash_pending',
        'giveflow_email_settings',
        'giveflow_fund_reassignments',
        'giveflow_fx_rates',
        'giveflow_gateway_config',
        'giveflow_gateway_reconcile_cursor',
        'giveflow_licensing_status',
        'giveflow_onboarding_campaign_id',
        'giveflow_onboarding_status',
        'giveflow_org_brand',
        'giveflow_org_profile',
        'giveflow_paypal_plans',
        'giveflow_paypal_product',
        'giveflow_portal_page_id',
        'giveflow_portal_page_version',
        'giveflow_privacy',
        // The key itself, not just the status cache beside it. It is a bearer
        // credential for the charity's paid entitlement, and it outlived every
        // GiveFlow file on the site: through a handover, a database export, a
        // backup handed to a contractor. Written by the licensing client
        // vendored into each paid add-on, which has no uninstall of its own.
        'giveflow_pro_license_key',
        'giveflow_receipt_settings',
        'giveflow_reference_settings',
        'giveflow_retention_cursor',
        'giveflow_retention_starts_at',
        'giveflow_roles',
        'giveflow_upgrade_routines_done',
        'giveflow_upgrade_routines_failed',
    ];

    /** Reference counters carry the year, so they are the one keyspace to match. */
    private const OPTION_PREFIXES = [
        'giveflow_reference_counter_',
    ];

    /**
     * How long a request to delete everything stays good for.
     *
     * The answer is given on the plugins screen and acted on by the very next
     * request, so minutes is generous. What it rules out is the flag outliving
     * the deactivation it belongs to: set the option, close the tab, and every
     * later deactivation from any route at all, a bulk action, WP-CLI, a host's
     * tooling, would find it still set and erase everything without asking.
     */
    private const INTENT_TTL = 300;

    /**
     * Whether someone asked, recently, for the data to go.
     *
     * Stored as the moment of the answer rather than a flag, so an answer that
     * was never acted on expires instead of waiting. A value this cannot read
     * as a timestamp counts as no answer, which is the safe direction.
     *
     * @since 1.0.0
     */
    public static function requested(): bool
    {
        $answeredAt = (int) get_option(self::OPT_IN, 0);

        return $answeredAt > 0 && (time() - $answeredAt) <= self::INTENT_TTL;
    }

    /** Spent, so nothing can act on the same answer twice. @since 1.0.0 */
    public static function forgetRequest(): void
    {
        delete_option(self::OPT_IN);
    }

    /**
     * Take the answer if there is a good one, leaving none behind.
     *
     * One call rather than asking and then clearing, because the two-step
     * version is one early return away from erasing on an answer it never
     * spent, which is the failure this whole guard exists to stop.
     *
     * @since 1.0.0
     */
    public static function claimRequest(): bool
    {
        if (! self::requested()) {
            return false;
        }

        self::forgetRequest();

        return true;
    }

    /**
     * Exactly what erase() would remove, without removing it.
     *
     * Deciding and deleting are separate so the decision can be asserted. A
     * test that ran the deletion would take the shared test database with it,
     * which means the only alternative is an untested wipe.
     *
     * @return array{tables: string[], options: string[]}
     * @since 1.0.0
     */
    public function plan(): array
    {
        return [
            'tables'  => $this->coreTables(),
            'options' => $this->optionsToDelete(),
        ];
    }

    /** @since 1.0.0 */
    public function erase(): void
    {
        // Before the tables go, so an add-on can still read what it needs to
        // clean up rows of its own that point at core.
        do_action('giveflow.uninstall');

        $plan = $this->plan();

        // Pages go first, while the tables are still there. wp_delete_post
        // fires WordPress's own post hooks, and this plugin is listening to
        // them: CampaignService::onPageDeleted looks the campaign up by page
        // id. Dropping first means our own listener queries a table we just
        // removed and takes the whole deactivation down with it.
        $this->deletePages($this->pageIds());

        // Also while the tables are there: the only pointer to a donor's
        // picture is a column of giveflow_donors, and the file outlives the row.
        $this->deleteAttachments($this->avatarAttachmentIds());

        $this->dropTables($plan['tables']);
        foreach ($plan['options'] as $option) {
            delete_option($option);
        }
        $this->removeCapabilities();
    }

    /**
     * @param string[] $tables
     * @since 1.0.0
     */
    private function dropTables(array $tables): void
    {
        global $wpdb;

        foreach ($tables as $table) {
            $full = $wpdb->prefix . $table;

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepare() has no placeholder for an identifier, so a table name can only be interpolated. $table comes from this class's own list and never from a request.
            $wpdb->query("DROP TABLE IF EXISTS `{$full}`");
            $wpdb->query("DROP TABLE IF EXISTS `{$full}_meta`");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            delete_option('queryable_' . $table . '_version');
        }
    }

    /**
     * Public so the confinement can be asserted directly. WordPress's test
     * harness rewrites DROP TABLE to DROP TEMPORARY TABLE, so a test cannot
     * observe the drop itself without destroying the shared test database; what
     * it can observe, and what actually protects the add-ons, is this list.
     *
     * @return string[] unprefixed
     * @since 1.0.0
     */
    public function coreTables(): array
    {
        $tables = [];

        foreach ((new CoreModule())->migrations() as $model) {
            if (! class_exists($model)) {
                continue;
            }
            $property = (new ReflectionClass($model))->getProperty('table');
            $property->setAccessible(true);
            $name = (string) $property->getValue(new $model());
            if ($name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * @return string[]
     * @since 1.0.0
     */
    private function optionsToDelete(): array
    {
        global $wpdb;

        $options = self::OPTIONS;

        foreach (self::OPTION_PREFIXES as $prefix) {
            $names = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like($prefix) . '%'
                )
            );
            foreach ((array) $names as $name) {
                $options[] = (string) $name;
            }
        }

        return array_values(array_unique($options));
    }

    /**
     * Pages core created and still names in a row of its own: the portal, and
     * each campaign's own page. Not every page carrying _giveflow_campaign_id,
     * because the peer-to-peer add-on puts that meta on its fundraiser and team
     * subpages too, and those are its to remove.
     *
     * @return int[]
     * @since 1.0.0
     */
    public function pageIds(): array
    {
        $ids = [(int) get_option('giveflow_portal_page_id', 0)];

        foreach (Campaign::query()->getAll() as $campaign) {
            $ids[] = (int) ($campaign->page_id ?? 0);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Pictures donors uploaded of themselves, held as WordPress attachments on
     * a public uploads URL. Nothing else names them as donor data, so read
     * after the drop they are unfindable and stay served forever.
     *
     * @return int[]
     * @since 1.0.0
     */
    public function avatarAttachmentIds(): array
    {
        $ids = Donor::query()
            ->whereIsNotNull('avatar_attachment_id')
            ->pluck('avatar_attachment_id');

        return array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    }

    /**
     * Force-deleted, unlike the pages: a site that asked for the donor data to
     * go has not asked to keep their photographs in the bin.
     *
     * @param int[] $ids
     * @since 1.0.0
     */
    private function deleteAttachments(array $ids): void
    {
        foreach ($ids as $id) {
            wp_delete_attachment($id, true);
        }
    }

    /**
     * Trashed rather than force-deleted: an organizer who wrote their own
     * content onto a campaign page should get it back out of the bin.
     *
     * @param int[] $ids
     * @since 1.0.0
     */
    private function deletePages(array $ids): void
    {
        foreach ($ids as $id) {
            if (get_post($id)) {
                wp_delete_post($id, false);
            }
        }
    }

    /**
     * Core's own capabilities by name, not everything matching giveflow_. An add-on
     * that is still installed keeps its caps: giveflow_manage_fundraisers belongs
     * to the peer-to-peer plugin and taking it would break a live site.
     *
     * Every registered role, because the roles screen grants these to editor
     * and below as readily as to the administrator, and what is not taken back
     * outlives the plugin in wp_user_roles. The roles themselves are left
     * alone: core creates none, so anything this removed would be somebody
     * else's.
     *
     * Public for the same reason as coreTables(): a test cannot call erase()
     * without taking the shared test database with it.
     *
     * @since 1.0.0
     */
    public function removeCapabilities(): void
    {
        $caps = [...Capabilities::ALL, Capabilities::MANAGE];

        foreach (wp_roles()->role_objects as $role) {
            foreach ($caps as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
}
