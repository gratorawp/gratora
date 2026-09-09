<?php

declare(strict_types=1);

namespace Gratora\Foundation;

use Gratora\Analytics\ErrorLog;
use Gratora\Async\AsyncDispatcher;
use Gratora\Campaigns\CampaignPermalinks;
use Gratora\Core\Activator;
use Gratora\Core\CoreModule;
use Gratora\Donors\DonorRetention;
use Gratora\Donors\Portal\PortalPage;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Commands\CommandRegistry;
use Gratora\Foundation\Container\Container;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Foundation\Time\SystemClock;
use Gratora\Foundation\Uninstall\DataEraser;
use Gratora\Foundation\Upgrade\MigrationLock;
use Gratora\Foundation\Upgrade\SchemaGuard;
use Gratora\Foundation\Upgrade\UpgradeJob;
use Gratora\Foundation\Upgrade\UpgradeRunner;
use Gratora\Funds\FundRepository;
use Gratora\Gateways\GatewayManager;
use Gratora\Onboarding\Onboarding;

/** @since 1.0.0 */
final class Plugin
{
    private static ?self $instance = null;
    private static bool $booted = false;

    public readonly Container $container;
    public readonly ModuleManager $modules;

    /** @since 1.0.0 */
    private function __construct()
    {
        $this->container = new Container();
        $this->modules   = new ModuleManager($this->container);

        $this->container->instance(Container::class, $this->container);
        $this->container->instance(ModuleManager::class, $this->modules);
    }

    /** @since 1.0.0 */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Idempotent.
     *
     * @since 1.0.0
     */
    public static function boot(): void
    {
        // Guard against double-boot: CLI/test scripts may call boot().
        if (self::$booted) return;
        self::$booted = true;

        $self = self::instance();

        // Guarded so boot() is safe even when modules were already registered
        // earlier in the same request - e.g. the integration test bootstrap
        // migrates the schema (which registers modules) before plugins_loaded.
        if (! $self->modules->get('core')) {
            $self->modules->register(new CoreModule());
        }

        // Allow external modules to register on this hook.
        do_action('gratora.modules.register', $self->modules);

        $self->modules->bootAll();

        // After bootAll, not inside core's own boot: an add-on attaches its
        // listener during its boot and would miss a broadcast fired earlier.
        if ($self->container->has(GatewayManager::class)) {
            do_action(
                'gratora.gateways.register',
                $self->container->get(GatewayManager::class),
                $self->container
            );
        }

        // Command metadata carries translated summaries and schema labels, so
        // no pack may be built before init: WordPress resolves the catalogue
        // against the site locale while the current user is still unknown, and
        // logs _doing_it_wrong for the domain on every request.
        //
        // Priority 5 puts this one step behind core's own pack, which
        // CoreModule registers at 4, and leaves the registry complete for
        // default-priority init handlers. Add-ons attach their listener during
        // their boot(), on this request's plugins_loaded, so the broadcast
        // reaches every pack.
        add_action('init', static function () use ($self): void {
            // Broadcast once. init can fire again, and a pack offering names
            // the registry already holds is refused by throwing.
            static $broadcast = false;
            if ($broadcast || ! $self->container->has(CommandRegistry::class)) {
                return;
            }
            $broadcast = true;

            do_action(
                'gratora.commands.register',
                $self->container->get(CommandRegistry::class),
                $self->container
            );
        }, 5);

        // Virtual `gratora_access` cap for admin-menu visibility (super-admins,
        // the manage_gratora umbrella, or any granular gratora_* cap holder). REST
        // endpoints still enforce per-area granular caps.
        add_filter('user_has_cap', [Capabilities::class, 'grantMetaCaps']);

        // Activation hooks don't fire on plugin updates, so a release that adds
        // a table or column would never migrate on a normal update, causing
        // "unknown column" errors until a reactivation. Run the schema
        // migration once per GRATORA_DB_VERSION bump (cheap on steady state: one
        // option read). Priority 99 so tables exist before the portal heal.
        // A closure declared here, not an array callable: a test that fires
        // wp_loaded with only this file's callbacks standing identifies them by
        // the file the closure was defined in.
        add_action('wp_loaded', static function (): void {
            self::runSchemaGate();
        }, 99);

        SchemaGuard::registerNotice();

        // The bump above is the only thing that queues a drain, so a release
        // adding a routine and no schema change would never run it, and a queue
        // cleared by the host (or a drain that dies mid-way) would never come
        // back. Admin-only: one option read, and the scheduler lookup happens
        // only while something is actually outstanding.
        add_action('admin_init', static function (): void {
            $c = self::instance()->container;
            UpgradeJob::reconcile($c->get(AsyncDispatcher::class), $c->get(UpgradeRunner::class));
        });

        // Re-ensure the donor portal page once per GRATORA_VERSION bump so existing
        // installs that skip a reactivation still get the page (and recover from
        // manual deletion). Cheap on steady state (one option read).
        add_action('wp_loaded', static function (): void {
            (new PortalPage())->maybeHeal();
        }, 100);

        do_action('gratora.booted', $self);
    }

    /** @since 1.0.0 */
    public static function runSchemaGate(): void
    {
        // Anything thrown here reaches no handler and takes the front end
        // with it, on every request, including the admin screen somebody
        // would use to switch the plugin off.
        try {
            $fresh = get_option(SchemaGuard::OPTION, null) === null;

            if (get_option(SchemaGuard::OPTION) !== GRATORA_DB_VERSION) {
                // One request migrates. The rest of a burst would otherwise run
                // the whole pass concurrently against the same tables.
                if (! MigrationLock::claim()) {
                    return;
                }

                try {
                    self::migrateSchema();

                    // Nothing below is safe against tables that are not there,
                    // and the stamp is what brings this gate back next request.
                    if (! SchemaGuard::stampWhenComplete()) {
                        return;
                    }

                    self::finishActivation($fresh);

                    // Schema first, then data. A routine that backfills a
                    // column the same release added would otherwise run
                    // against a table without it. Queued rather than run here:
                    // a backfill over a few hundred thousand donations does
                    // not belong in the request that noticed the plugin had
                    // been updated.
                    self::instance()->container->get(UpgradeJob::class)->start();
                } finally {
                    MigrationLock::release();
                }

                return;
            }

            self::finishActivation($fresh);
        } catch (\Throwable $e) {
            ErrorLog::record('schema_guard', $e->getMessage());
        }
    }

    /**
     * Register modules and run all model migrations (schema only). Idempotent
     * and safe to call before plugins_loaded - the integration test bootstrap
     * calls this so the gratora_* tables exist before boot() constructs services
     * (e.g. IdentityHasher) that read them.
     *
     * @since 1.0.0
     */
    public static function migrateSchema(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $self = self::instance();

        // CLI/early activation may run before plugins_loaded; registration is idempotent.
        if (! $self->modules->get('core')) {
            $self->modules->register(new CoreModule());
        }

        foreach ($self->modules->allMigrations() as $modelClass) {
            if (method_exists($modelClass, 'migrate')) {
                $modelClass::migrate(true);
            }
        }
    }

    /**
     * Runs the activation work on the first request that can, whatever stopped
     * the activation hook from finishing it.
     *
     * Keyed off the activation record and not the schema stamp. Those are
     * written at different points, and anything thrown between them leaves a
     * site with tables but no default fund, capabilities or portal page, on
     * which activation hooks never fire again. activate() is idempotent, so
     * asking every request costs one option read once it has run.
     *
     * @param ?bool $fresh Whether the schema had never been stamped, read
     *     before the stamp this request may already have written.
     * @since 1.0.0
     */
    private static function finishActivation(?bool $fresh = null): void
    {
        if (get_option(Activator::OPT_ACTIVATED_AT, false) !== false) {
            return;
        }

        if (SchemaGuard::missingTables() !== []) {
            return;
        }

        self::onActivation($fresh);
    }

    /**
     * Accept WordPress’s $network_wide argument separately so activation can auto-detect
     * $fresh.
     *
     * @since 1.0.0
     */
    public static function onPluginActivated(bool $networkWide = false): void
    {
        self::onActivation(null);
    }

    /** @since 1.0.0 */
    public static function onActivation(?bool $fresh = null): void
    {
        try {
            self::activate($fresh);
        } catch (\Throwable $e) {
            // WordPress renders anything thrown out of an activation hook as
            // "Plugin could not be activated because it triggered a fatal
            // error" and nothing else, on a screen with no way to read more.
            // Recorded rather than only logged: toDebugLog no-ops unless
            // WP_DEBUG is on, which is the case on the sites this happens to.
            ErrorLog::record('activation', $e->getMessage());
        }
    }

    /**
     * @param ?bool $fresh Null to read it from the schema stamp, which is only
     *     correct before anything has written one this request.
     * @since 1.0.0
     */
    private static function activate(?bool $fresh = null): void
    {
        $fresh ??= get_option(SchemaGuard::OPTION, null) === null;

        self::migrateSchema();

        // Before the schema check, because it needs no tables and it is what
        // lets whoever switched the plugin on read the notice that follows.
        Capabilities::applyMapping(
            Capabilities::currentMapping()
        );

        // Stamping a version the tables do not match disarms the wp_loaded gate
        // that would otherwise migrate again, so a host that refuses CREATE
        // gets a site that never recovers. Everything below writes to those
        // tables in any case.
        if (! SchemaGuard::stampWhenComplete()) {
            return;
        }

        // A new site has nothing to migrate. Stamping the routines instead of
        // running them keeps a backfill from walking an empty table, and stops
        // one that assumes the shape an older release wrote from ever seeing a
        // table that release never touched.
        if ($fresh) {
            UpgradeRunner::markAllDone(new UpgradeRunner(CoreModule::upgradeRoutines()));
        }

        // The sweep needs a start date to exist before anything can read one.
        // It is pushed forward again wherever erasure gets switched on, which
        // is what actually buys an org the time to notice.
        DonorRetention::deferBy();

        Onboarding::maybeSeedOnActivation();

        // Activation-time only; separate from the runtime service graph.
        (new Activator(
            new FundRepository(),
            new SystemClock()
        ))->activate();

        // The donor portal page hosts [gratora_donor_portal] and is what every
        // magic-link email points at - create or adopt it before any donor
        // ever needs the URL.
        (new PortalPage())->ensure();
        update_option(PortalPage::OPTION_VERSION, GRATORA_VERSION, false);

        (new CampaignPermalinks())->addRule();
        flush_rewrite_rules();

        do_action('gratora.activated');
    }

    /**
     * Switch off the add-ons that cannot run without core.
     *
     * An add-on extends core's classes, and core's autoloader goes with core, so
     * an add-on left active afterwards fatals the moment anything touches one of
     * those classes, including its own deactivation hook. That leaves the site
     * owner unable to switch off the thing that is breaking their site from the
     * screen that would switch it off.
     *
     * Done here, inside core's own deactivation, because core is still loaded
     * for the rest of this request: each add-on's deactivation hook runs with
     * the classes it expects still in memory.
     *
     * @since 1.0.0
     */
    private static function deactivateDependents(): void
    {
        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // The slug an add-on names is the one the directory distributes core
        // under, which is the text domain, not whatever folder this checkout
        // happens to sit in. plugin_basename() is no good either: it returns the
        // whole absolute path when the plugin is outside the registered plugin
        // directory, and the slug would then match no add-on at all.
        $slug = (string) (get_file_data(GRATORA_FILE, ['TextDomain' => 'Text Domain'])['TextDomain'] ?? '');
        $here = basename(dirname(GRATORA_FILE));

        $dependents = [];

        foreach ((array) get_option('active_plugins', []) as $plugin) {
            $plugin = (string) $plugin;
            if (dirname($plugin) === $here) {
                continue;
            }

            $file = WP_PLUGIN_DIR . '/' . $plugin;
            if (! is_readable($file)) {
                continue;
            }

            // The header WordPress itself reads for plugin dependencies, so an
            // add-on declares this once and both core and WordPress honour it.
            $requires = get_file_data($file, ['RequiresPlugins' => 'Requires Plugins'])['RequiresPlugins'] ?? '';
            $names    = array_filter(array_map('trim', explode(',', (string) $requires)));

            if ($slug !== '' && in_array($slug, $names, true)) {
                $dependents[] = $plugin;
            }
        }

        /**
         * Add-ons that must go off with core.
         *
         * The header covers anything that declares itself properly; this is for
         * an add-on that cannot, and for tests.
         *
         * @param list<string> $dependents Plugin basenames.
         * @since 1.0.0
         */
        $dependents = (array) apply_filters('gratora.dependent_plugins', $dependents);

        if ($dependents === []) {
            return;
        }

        $dependents = array_values(array_unique($dependents));

        // Not silent: silent is what skips deactivate_{$plugin}, which is the
        // action register_deactivation_hook installs, so each add-on's own
        // cleanup never ran and its cron jobs and rewrite rules outlived it.
        deactivate_plugins($dependents);

        // WordPress is inside its own deactivate_plugins() for core and writes
        // active_plugins from a copy it read before this hook ran, so the write
        // just made is about to be overwritten and the add-ons would come back
        // on. Strip them from that write too, once.
        $strip = static function ($value) use (&$strip, $dependents) {
            remove_filter('pre_update_option_active_plugins', $strip);

            return array_values(array_diff((array) $value, $dependents));
        };
        add_filter('pre_update_option_active_plugins', $strip);
    }

    /**
     * @param bool $networkDeactivating WordPress passes this to the hook; true
     *                                  when the plugin is being switched off
     *                                  for the whole network.
     *
     * @since 1.0.0
     */
    public static function onDeactivation(bool $networkDeactivating = false): void
    {
        // Anything that reads Gratora's own tables runs before the wipe, because
        // the plugin is still loaded and hooked for the rest of this request.
        self::deactivateDependents();

        do_action('gratora.deactivated');

        // Deleted rather than flushed, and after the dependents: a flush from a
        // plugin that is still loaded stores its own rules again, and
        // CampaignPermalinks::addRule has already run on init. WordPress
        // rebuilds the option on the next permalink request, by which time the
        // rule is gone with the plugin. Left behind, it rewrote every URL under
        // /campaigns/ on a site that had moved on to ordinary pages.
        delete_option('rewrite_rules');

        // init reinstalls these on reactivation, and a run with no callback
        // still schedules its successor, so a deactivated plugin's sweeps
        // regenerate for as long as the site lives.
        AsyncDispatcher::forgetRecurring();

        // The answer is spent by finishing, not by starting. An erase that dies
        // on its third site of twelve has to leave the plugin delete something
        // to act on, or the owner keeps donors they were told were gone.
        if (! DataEraser::requested()) {
            return;
        }

        $complete = false;

        try {
            $complete = self::eraseEverywhere($networkDeactivating);
        } catch (\Throwable $e) {
            // WordPress writes active_plugins only after this hook returns, so
            // an exception escaping here leaves the plugin switched on with its
            // tables gone and every request from then on fatal, including the
            // screen you would deactivate it from.
            ErrorLog::toDebugLog('deleting data on deactivation failed: ' . $e->getMessage());
        }

        // No return between the check above and this line: settling the answer
        // exactly once is the property the old claim-then-erase call held.
        $complete ? DataEraser::forgetRequest() : DataEraser::renewRequest();
    }

    /**
     * The wipe, over every site the deactivation covers.
     *
     * register_deactivation_hook fires exactly once for a network-wide
     * deactivation, in the main site's context. Erasing only the current blog
     * left every other site's donor rows, consent history, donations and
     * receipts in place, and the later plugin delete found the answer already
     * spent and erased nothing: an explicit request to delete all donor data
     * deleted it from one site out of twelve, with the screen saying it was
     * gone.
     *
     * @return bool whether every site was erased. The caller settles the answer
     *              on it, because a wipe that stopped needs a retry to survive.
     */
    private static function eraseEverywhere(bool $networkWide): bool
    {
        if (! $networkWide || ! is_multisite()) {
            (new DataEraser())->erase();

            return true;
        }

        // Scoped to this network: a multi-network install's other networks are
        // not what was deactivated.
        $sites = get_sites([
            'fields'     => 'ids',
            'number'     => 0,
            'network_id' => get_current_network_id(),
        ]);

        $complete = true;

        foreach ($sites as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                (new DataEraser())->erase();
            } catch (\Throwable $e) {
                // A site the plugin was never active on has no tables to drop.
                // Whatever the reason, one site cannot be allowed to end the
                // wipe: every site after it would silently keep its donors.
                $complete = false;
                ErrorLog::toDebugLog("deleting data on site {$siteId} failed: " . $e->getMessage());
            } finally {
                // Without this the switch outlives a failure and every site
                // after it is erased against the wrong blog's tables.
                restore_current_blog();
            }
        }

        return $complete;
    }
}
