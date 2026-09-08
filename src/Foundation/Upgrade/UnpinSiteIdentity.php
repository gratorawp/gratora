<?php

declare(strict_types=1);

namespace FundKit\Foundation\Upgrade;

use FundKit\Settings\SettingsService;

/**
 * Remove stored copies equal to current dynamic defaults so site identity and translated email
 * text follow later changes.
 *
 * @since 1.0.0
 */
final class UnpinSiteIdentity implements UpgradeRoutine
{
    /** @since 1.0.0 */
    public function id(): string
    {
        return '2026-09-06-unpin-site-identity';
    }

    /** @since 1.0.0 */
    public function description(): string
    {
        return __('Letting the sender name and organisation name follow the site again.', 'fundraising-toolkit');
    }

    /** @since 1.0.0 */
    public function step(): bool
    {
        $this->unpinEmail();
        $this->unpinOrgProfile();

        return true;
    }

    /** @since 1.0.0 */
    private function unpinEmail(): void
    {
        $stored = get_option('fundkit_email_settings', []);
        if (! is_array($stored) || $stored === []) return;

        $next = (new SettingsService())->withoutResolvedDefaults('email', $stored);
        if ($next !== $stored) {
            update_option('fundkit_email_settings', $next, false);
        }
    }

    /** @since 1.0.0 */
    private function unpinOrgProfile(): void
    {
        $stored = get_option('fundkit_org_profile', []);
        if (! is_array($stored) || $stored === []) return;

        $next = $stored;
        foreach (['name' => (string) get_bloginfo('name'), 'email' => (string) get_option('admin_email')] as $key => $live) {
            if (($next[$key] ?? null) === $live) unset($next[$key]);
        }

        if ($next !== $stored) {
            update_option('fundkit_org_profile', $next, false);
        }
    }
}
