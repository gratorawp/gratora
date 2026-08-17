<?php

declare(strict_types=1);

namespace Dono\Foundation;

use Dono\Analytics\ErrorLog;
use Dono\Campaigns\CampaignPermalinks;
use Dono\Core\Activator;
use Dono\Core\CoreModule;
use Dono\Donors\DonorRetention;
use Dono\Foundation\Commands\CommandRegistry;
use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\Container\Container;
use Dono\Foundation\Modules\ModuleManager;
use Dono\Foundation\Uninstall\DataEraser;
use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Upgrade\SchemaGuard;
use Dono\Foundation\Upgrade\UpgradeJob;
use Dono\Foundation\Upgrade\UpgradeRunner;
use Dono\Foundation\Time\SystemClock;
use Dono\Funds\FundRepository;
use Dono\Onboarding\Onboarding;

/**
 * Plugin singleton. Owns the Container and ModuleManager and runs the boot pipeline.
 *
 * @since 1.0.0
 */
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
     * Register and boot all modules. Idempotent.
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
        do_action('dono.modules.register', $self->modules);

        $self->modules->bootAll();

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
                'dono.commands.register',
                $self->container->get(CommandRegistry::class),
                $self->container
            );
        }, 5);

        // Virtual `dono_access` cap for admin-menu visibility (super-admins,
        // the manage_dono umbrella, or any granular dono_* cap holder). REST
        // endpoints still enforce per-area granular caps.
        add_filter('user_has_cap', [Capabilities::class, 'grantMetaCaps']);

        // Activation hooks don't fire on plugin updates, so a release that adds
        // a table or column would never migrate on a normal update, causing
        // "unknown column" errors until a reactivation. Run the schema
        // migration once per DONO_DB_VERSION bump (cheap on steady state: one
        // option read). Priority 99 so tables exist before the portal heal.
        add_action('wp_loaded', static function (): void {
            // Anything thrown here reaches no handler and takes the front end
            // with it, on every request, including the admin screen somebody
            // would use to switch the plugin off.
            try {
                $fresh = get_option(SchemaGuard::OPTION, null) === null;

                if (get_option(SchemaGuard::OPTION) !== DONO_DB_VERSION) {
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

                    return;
                }

                self::finishActivation($fresh);
            } catch (\Throwable $e) {
                ErrorLog::record('schema_guard', $e->getMessage());
            }
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

        // Re-ensure the donor portal page once per DONO_VERSION bump so existing
        // installs that skip a reactivation still get the page (and recover from
        // manual deletion). Cheap on steady state (one option read).
        add_action('wp_loaded', static function (): void {
            (new PortalPage())->maybeHeal();
        }, 100);

        do_action('dono.booted', $self);
    }

    /**
     * Register modules and run all model migrations (schema only). Idempotent
     * and safe to call before plugins_loaded - the integration test bootstrap
     * calls this so the dono_* tables exist before boot() constructs services
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

        // The donor portal page hosts [dono_donor_portal] and is what every
        // magic-link email points at - create or adopt it before any donor
        // ever needs the URL.
        (new PortalPage())->ensure();
        update_option(PortalPage::OPTION_VERSION, DONO_VERSION, false);

        // Register campaign rewrite and flush so permalinks resolve immediately.
        (new CampaignPermalinks())->addRule();
        flush_rewrite_rules();

        do_action('dono.activated');
    }

    /** @since 1.0.0 */
    public static function onDeactivation(): void
    {
        // Anything that reads Dono's own tables runs before the wipe, because
        // the plugin is still loaded and hooked for the rest of this request.
        flush_rewrite_rules();

        do_action('dono.deactivated');

        // Taking the answer is what spends it, so an erase that fails partway
        // cannot be re-run by some later deactivation nobody asked about.
        if (! DataEraser::claimRequest()) {
            return;
        }

        try {
            (new DataEraser())->erase();
        } catch (\Throwable $e) {
            // WordPress writes active_plugins only after this hook returns, so
            // an exception escaping here leaves the plugin switched on with its
            // tables gone and every request from then on fatal, including the
            // screen you would deactivate it from.
            ErrorLog::toDebugLog('deleting data on deactivation failed: ' . $e->getMessage());
        }
    }
}
