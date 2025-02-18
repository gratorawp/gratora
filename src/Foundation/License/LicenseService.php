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

    /** License status string for display. */
    public function status(): string
    {
        return $this->isPro() ? 'active' : 'inactive';
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
            'status'   => $features !== [] ? 'active' : 'inactive',
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
