<?php

declare(strict_types=1);

namespace Dono\Foundation\Modules;

use Dono\Foundation\Container\Container;
use RuntimeException;

/**
 * Registry and boot orchestrator for Dono modules.
 *
 * Boot order respects requires(). A module is skipped when unlicensed,
 * when a required module is absent, or when its requires()['core'] constraint
 * is not satisfied by the running DONO_VERSION.
 *
 * @since 1.0.0
 */
final class ModuleManager
{
    /** @var array<string, DonoModule> */
    private array $modules = [];

    /** @var array<string, bool> */
    private array $booted = [];

    /**
     * Modules skipped because their `requires()['core']` constraint was not
     * met: id => [running DONO_VERSION, declared constraint].
     *
     * @var array<string, array{0:string,1:string}>
     */
    private array $incompatible = [];

    /** @since 1.0.0 */
    public function __construct(private Container $container)
    {
    }

    /** @since 1.0.0 */
    public function register(DonoModule $module): void
    {
        $id = $module->id();

        if (isset($this->modules[$id])) {
            throw new RuntimeException("Dono module '{$id}' is already registered.");
        }

        $this->modules[$id] = $module;
    }

    /** @since 1.0.0 */
    public function get(string $id): ?DonoModule
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * @return array<string, DonoModule>
     * @since 1.0.0
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Modules skipped because their requires()['core'] constraint was not met.
     *
     * @return array<string, array{0:string,1:string}> id => [DONO_VERSION, constraint]
     * @since 1.0.0
     */
    public function incompatible(): array
    {
        return $this->incompatible;
    }

    /**
     * Return the status of a module: booted | unlicensed | unmet-deps | incompatible | not-registered.
     *
     * @since 1.0.0
     */
    public function status(string $id): string
    {
        if (! isset($this->modules[$id])) {
            return 'not-registered';
        }
        if (isset($this->incompatible[$id])) {
            return 'incompatible';
        }

        $module = $this->modules[$id];
        if (! $module->isLicensed()) {
            return 'unlicensed';
        }

        $required = $module->requires()['modules'] ?? [];
        foreach ($required as $depId) {
            if (! isset($this->modules[$depId])) {
                return 'unmet-deps';
            }
        }

        return ($this->booted[$id] ?? false) ? 'booted' : 'unmet-deps';
    }

    /**
     * Boot every registered module in dependency order, skipping unlicensed ones.
     *
     * @since 1.0.0
     */
    public function bootAll(): void
    {
        foreach (array_keys($this->modules) as $id) {
            $this->bootModule($id);
        }
    }

    /** @since 1.0.0 */
    private function bootModule(string $id): void
    {
        if (isset($this->booted[$id])) {
            return;
        }

        $module = $this->modules[$id] ?? null;
        if (! $module) {
            return;
        }

        $coreConstraint = $module->requires()['core'] ?? null;
        if (is_string($coreConstraint) && $coreConstraint !== ''
            && ! VersionConstraint::satisfies(DONO_VERSION, $coreConstraint)
        ) {
            $this->incompatible[$id] = [DONO_VERSION, $coreConstraint];
            $this->booted[$id] = false;
            do_action('dono.module.incompatible', $id, DONO_VERSION, $coreConstraint);
            return;
        }

        if (! $module->isLicensed()) {
            $this->booted[$id] = false;
            return;
        }

        $required = $module->requires()['modules'] ?? [];
        foreach ($required as $depId) {
            if (! isset($this->modules[$depId])) {
                $this->booted[$id] = false;
                return;
            }
            $this->bootModule($depId);
        }

        $module->boot($this->container);
        $this->booted[$id] = true;
    }

    /**
     * Collect all module-owned model classes for migrations.
     *
     * @return array<class-string<\Dono\Vendor\Queryable\Model>>
     * @since 1.0.0
     */
    public function allMigrations(): array
    {
        $out = [];
        foreach ($this->modules as $module) {
            foreach ($module->migrations() as $modelClass) {
                $out[] = $modelClass;
            }
        }
        return $out;
    }
}
