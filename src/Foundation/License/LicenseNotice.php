<?php

declare(strict_types=1);

namespace Dono\Foundation\License;

/**
 * Tells an admin their license needs attention, on any screen.
 *
 * The Licenses page shows per add-on status, but nobody visits it unprompted,
 * so a refused key stays invisible until something stops working.
 *
 * @version 1.0.0
 */
final class LicenseNotice
{
    private const OPTION_KEY = 'dono_pro_license_key';

    public function __construct(private readonly LicenseService $license)
    {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        // Already on the screen that says all of this.
        if (($_GET['page'] ?? '') === 'dono-settings') {
            return;
        }

        $addons = $this->license->entitlements();
        if ($addons === []) {
            return; // Nothing paid is installed, so there is nothing to license.
        }

        $refused = $this->license->unlicensed();
        if ($refused !== []) {
            $this->notice(
                sprintf(
                    /* translators: %s: comma-separated add-on names */
                    __('Your license does not cover %s. They keep running for now, but they will not receive updates or security fixes.', 'dono'),
                    $this->names($refused)
                )
            );

            return;
        }

        $lapsing = $this->license->lapsing();
        if ($lapsing !== []) {
            $this->notice(
                sprintf(
                    /* translators: %s: comma-separated add-on names */
                    __('The license for %s has lapsed. Renew to keep receiving updates and security fixes.', 'dono'),
                    $this->names($lapsing)
                )
            );

            return;
        }

        if ((string) get_option(self::OPTION_KEY, '') === '') {
            $this->notice(
                __('Your Dono add-ons are not linked to a license key, so they will not receive updates or security fixes.', 'dono')
            );
        }
    }

    /** @param array<int,array{name:string}> $addons */
    private function names(array $addons): string
    {
        return implode(', ', array_map(static fn (array $a): string => (string) $a['name'], $addons));
    }

    /**
     * Always a warning, never an error: an unlicensed add-on keeps running, it
     * just stops receiving updates. Red would overstate it.
     */
    private function notice(string $message): void
    {
        printf(
            '<div class="notice dono-admin-notice" role="alert" style="%s"><strong>%s</strong> %s <a href="%s">%s</a></div>',
            esc_attr(
                'border:1px solid #e5e7eb;border-left:3px solid #b54708;border-radius:8px;'
                . 'background:#fffaf5;color:#b54708;padding:11px 14px;'
                . "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;"
                . 'font-size:13px;line-height:1.45;'
            ),
            esc_html__('Dono:', 'dono'),
            esc_html($message),
            esc_url(admin_url('admin.php?page=dono-settings#licenses')),
            esc_html__('Manage licenses', 'dono')
        );
    }
}
