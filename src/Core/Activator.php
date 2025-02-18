<?php

declare(strict_types=1);

namespace Dono\Core;

use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\Clock;
use Dono\Funds\Fund;
use Dono\Funds\FundRepository;

/**
 * Idempotent activation: each step checks state and only acts on what's missing.
 *
 * @version 1.0.0
 */
final class Activator
{
    public const OPT_ACTIVATED_AT = 'dono_activated_at';
    public const OPT_ORG_PROFILE  = 'dono_org_profile';
    public const CAP_MANAGE       = 'manage_dono';

    public function __construct(
        private FundRepository $funds,
        private Clock $clock,
    ) {
    }

    /** Runs all activation steps idempotently. */
    public function activate(): void
    {
        $this->seedDefaultFund();
        $this->grantCapabilities();
        $this->seedOrgProfile();
        $this->seedReferenceSettings();
        $this->markActivated();

        do_action('dono.activator.ran');
    }

    private function seedReferenceSettings(): void
    {
        if (get_option(ReferenceGenerator::OPTION_SETTINGS, false) !== false) return;
        add_option(ReferenceGenerator::OPTION_SETTINGS, ReferenceGenerator::DEFAULT_SETTINGS, '', false);
    }

    private function seedDefaultFund(): void
    {
        if ($this->funds->default() !== null) return;
        if ($this->funds->findByCode('general') !== null) return;

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $fund = Fund::make();
        $fund->code           = 'general';
        $fund->name           = __('General', 'dono');
        $fund->description    = __('Default fund for unrestricted donations.', 'dono');
        $fund->is_restricted  = false;
        $fund->is_default     = true;
        $fund->is_active      = true;
        $fund->sort_order     = 0;
        $fund->raised_cents   = 0;
        $fund->created_at     = $now;
        $fund->updated_at     = $now;
        $fund->save();
    }

    private function grantCapabilities(): void
    {
        $admin = get_role('administrator');
        if ($admin && ! $admin->has_cap(self::CAP_MANAGE)) {
            $admin->add_cap(self::CAP_MANAGE);
        }
    }

    private function seedOrgProfile(): void
    {
        if (get_option(self::OPT_ORG_PROFILE, false) !== false) return;

        add_option(self::OPT_ORG_PROFILE, [
            'name'          => (string) get_bloginfo('name'),
            'address_lines' => [],
            'tax_id'        => '',
            'email'         => (string) get_option('admin_email'),
        ], '', false);
    }

    private function markActivated(): void
    {
        if (get_option(self::OPT_ACTIVATED_AT, false) !== false) return;
        add_option(self::OPT_ACTIVATED_AT, $this->clock->now()->format('c'), '', false);
    }
}
