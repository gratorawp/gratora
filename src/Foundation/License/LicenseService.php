<?php

declare(strict_types=1);

namespace Dono\Foundation\License;

use Dono\Foundation\Modules\DonoModule;
use Dono\Foundation\Modules\ModuleManager;

/**
 * Entitlement queries over the installed modules. isPro() reflects whether a
 * booted module reports the TIER_PRO tier - a structural fact about what is
 * installed, not a runtime flag a site can toggle.
 *
 * @version 2.0.0
 */
final class LicenseService
{
    public function __construct(private ?ModuleManager $modules = null)
    {
    }

    /** Whether any booted module reports the TIER_PRO tier. */
    public function isPro(): bool
    {
        return $this->proFeatures() !== [];
    }

    /**
     * Entitlement flags = ids of the booted TIER_PRO modules.
     *
     * @return string[]
     */
    public function features(): array
    {
        return $this->proFeatures();
    }

    /** Whether a specific module (by id) is active. */
    public function can(string $feature): bool
    {
        return in_array($feature, $this->proFeatures(), true);
    }

    /**
     * License status string for display: active | grace | expired | revoked |
     * inactive. The dono-licensing client (vendored in each Pro add-on) sets
     * dono.pro.license_status from the server's signed response. When no client
     * is loaded, the filter passes through the possession-based default so the
     * admin still reads sensibly.
     */
    public function status(): string
    {
        $default = $this->isPro() ? 'active' : 'inactive';
        $status  = apply_filters('dono.pro.license_status', $default);

        return is_string($status) && $status !== '' ? $status : $default;
    }

    /**
     * Booted Pro add-ons as id + human-name pairs, for admin display. Falls
     * back to the id when a module has no resolvable name.
     *
     * @return array<int,array{id:string,name:string}>
     */
    public function addons(): array
    {
        if ($this->modules === null) {
            return [];
        }

        $addons = [];
        foreach ($this->proFeatures() as $id) {
            $module   = $this->modules->get($id);
            $addons[] = [
                'id'   => $id,
                'name' => $module !== null ? $module->name() : $id,
            ];
        }

        return $addons;
    }

    /**
     * @return array{active:bool,features:string[],status:string}
     */
    public function snapshot(): array
    {
        $features = $this->proFeatures();

        return [
            'active'   => $features !== [],
            'features' => $features,
            'status'   => $this->status(),
        ];
    }

    /** @return string[] */
    private function proFeatures(): array
    {
        if ($this->modules === null) {
            return [];
        }

        $features = [];
        foreach ($this->modules->all() as $id => $module) {
            if ($module->tier() === DonoModule::TIER_PRO
                && $this->modules->status($id) === 'booted'
            ) {
                $features[] = $id;
            }
        }

        return $features;
    }
}
