<?php

declare(strict_types=1);

namespace Gratora\Core;

use Gratora\Foundation\References\ReferenceGenerator;
use Gratora\Foundation\Time\Clock;
use Gratora\Foundation\Uninstall\DataEraser;
use Gratora\Funds\Fund;
use Gratora\Funds\FundRepository;

/**
 * Idempotent activation: each step checks state and only acts on what's missing.
 *
 * @since 1.0.0
 */
final class Activator
{
    public const OPT_ACTIVATED_AT = 'gratora_activated_at';
    public const CAP_MANAGE       = 'manage_gratora';

    /** @since 1.0.0 */
    public function __construct(
        private FundRepository $funds,
        private Clock $clock,
    ) {
    }

    /** @since 1.0.0 */
    public function activate(): void
    {
        $this->seedDefaultFund();
        $this->grantCapabilities();
        $this->seedReferenceSettings();
        $this->markActivated();
        // Switching Gratora back on withdraws a standing instruction to wipe. It
        // was given while removing the plugin, and it must not lie in wait to
        // destroy the records of a site that changed its mind.
        delete_option(DataEraser::OPT_IN);

        do_action('gratora.activator.ran');
    }

    /** @since 1.0.0 */
    private function seedReferenceSettings(): void
    {
        if (get_option(ReferenceGenerator::OPTION_SETTINGS, false) !== false) return;
        add_option(ReferenceGenerator::OPTION_SETTINGS, ReferenceGenerator::DEFAULT_SETTINGS, '', false);
    }

    /** @since 1.0.0 */
    private function seedDefaultFund(): void
    {
        if ($this->funds->default() !== null) return;
        if ($this->funds->findByCode('general') !== null) return;

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $fund = Fund::make();
        $fund->code           = 'general';
        $fund->name           = __('General', 'gratora-donation-platform');
        $fund->description    = __('Default fund for unrestricted donations.', 'gratora-donation-platform');
        $fund->is_restricted  = false;
        $fund->is_default     = true;
        $fund->is_active      = true;
        $fund->sort_order     = 0;
        $fund->raised_cents   = 0;
        $fund->created_at     = $now;
        $fund->updated_at     = $now;
        $fund->save();
    }

    /** @since 1.0.0 */
    private function grantCapabilities(): void
    {
        $admin = get_role('administrator');
        if ($admin && ! $admin->has_cap(self::CAP_MANAGE)) {
            $admin->add_cap(self::CAP_MANAGE);
        }
    }

    /** @since 1.0.0 */
    private function markActivated(): void
    {
        if (get_option(self::OPT_ACTIVATED_AT, false) !== false) return;
        add_option(self::OPT_ACTIVATED_AT, $this->clock->now()->format('c'), '', false);
    }
}
