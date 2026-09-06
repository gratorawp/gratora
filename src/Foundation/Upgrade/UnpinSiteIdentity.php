<?php

declare(strict_types=1);

namespace FundKit\Foundation\Upgrade;

use FundKit\Settings\SettingsService;

/**
 * Lets the sender and the organisation follow the site again.
 *
 * The sender name, the sender address and the organisation name are resolved
 * from the site title and the admin address on every read, so a stored copy is
 * not a setting anyone chose: it is whatever those two happened to say on the
 * day something wrote them. A site that renames itself, or moves its admin
 * address off a departed member of staff, keeps sending receipts from the old
 * one forever, and no screen shows a value to correct.
 *
 * Template text is the same shape: the defaults pass through __(), so a stored
 * copy pins every donor's email to one locale.
 *
 * A value equal to what a read resolves today cannot be told apart from one an
 * admin typed on purpose, and the resolved one is what they would see either
 * way, so dropping it costs nothing.
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

    /**
     * Two options, so there is nothing to page.
     *
     * @since 1.0.0
     */
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
