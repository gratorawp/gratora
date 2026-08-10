<?php

declare(strict_types=1);

namespace Dono\Foundation;

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
     * Load text domain, register and boot all modules. Idempotent.
     *
     * @since 1.0.0
     */
    public static function boot(): void
    {
        // Guard against double-boot: CLI/test scripts may call boot().
        if (self::$booted) return;
        self::$booted = true;

        $self = self::instance();

        load_plugin_textdomain('dono', false, dirname(plugin_basename(DONO_FILE)) . '/languages');

        // Guarded so boot() is safe even when modules were already registered
        // earlier in the same request - e.g. the integration test bootstrap
        // migrates the schema (which registers modules) before plugins_loaded.
        if (! $self->modules->get('core')) {
            $self->modules->register(new CoreModule());
        }

        // Allow external modules to register on this hook.
        do_action('dono.modules.register', $self->modules);

        $self->modules->bootAll();

        // Broadcast the command registry now that every module has booted, so
        // add-on command packs registered via add_action('dono.commands.register')
        // in their boot() are honored (core's own commands are registered
        // directly in CoreModule::boot, independent of this hook).
        if ($self->container->has(CommandRegistry::class)) {
            do_action(
                'dono.commands.register',
                $self->container->get(CommandRegistry::class),
                $self->container
            );
        }

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
            if (get_option('dono_db_version') === DONO_DB_VERSION) {
                return;
            }
            self::migrateSchema();
            update_option('dono_db_version', DONO_DB_VERSION, false);

            // Schema first, then data. A routine that backfills a column the
            // same release added would otherwise run against a table without
            // it. Queued rather than run here: a backfill over a few hundred
            // thousand donations does not belong in the request that noticed
            // the plugin had been updated.
            self::instance()->container->get(UpgradeJob::class)->start();
        }, 99);

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

    /** @since 1.0.0 */
    public static function onActivation(): void
    {
        $fresh = get_option('dono_db_version', null) === null;

        self::migrateSchema();

        // A new site has nothing to migrate. Stamping the routines instead of
        // running them keeps a backfill from walking an empty table, and stops
        // one that assumes the shape an older release wrote from ever seeing a
        // table that release never touched.
        if ($fresh) {
            UpgradeRunner::markAllDone(new UpgradeRunner(CoreModule::upgradeRoutines()));
        }
        // Stamp the schema version so the boot-time gate doesn't re-migrate on
        // the first request after activation.
        update_option('dono_db_version', DONO_DB_VERSION, false);

        Capabilities::applyMapping(
            Capabilities::currentMapping()
        );

        // Retention destroys donor PII on a schedule and nothing asks first,
        // so it does not start today. An org importing years of history would
        // otherwise lose part of it before seeing the setting.
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

        if (! DataEraser::requested()) {
            return;
        }

        try {
            (new DataEraser())->erase();
        } catch (\Throwable $e) {
            // WordPress writes active_plugins only after this hook returns, so
            // an exception escaping here leaves the plugin switched on with its
            // tables gone and every request from then on fatal, including the
            // screen you would deactivate it from.
            error_log('[dono] deleting data on deactivation failed: ' . $e->getMessage());
        }
    }
}
