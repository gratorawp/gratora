<?php

declare(strict_types=1);

namespace Gratora\Foundation\License;

/**
 * Tells an admin their license needs attention, on any screen.
 *
 * The Licenses page shows per add-on status, but nobody visits it unprompted,
 * so a refused key stays invisible until something stops working.
 *
 * @since 1.0.0
 */
final class LicenseNotice
{

    /** @since 1.0.0 */
    public function __construct(private readonly LicenseService $license)
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /** @since 1.0.0 */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        // Already on the screen that says all of this.
        if ((isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '') === 'gratora-settings') {
            return;
        }

        $addons = $this->license->entitlements();
        if ($addons === []) {
            return; // Nothing paid is installed, so there is nothing to license.
        }

        $refused = $this->license->unlicensed();
        if ($refused !== []) {
            foreach (LicenseRefusals::group($refused) as $group) {
                $this->notice($group['headline'] . '. ' . $group['detail']);
            }

            return;
        }

        $lapsing = $this->license->lapsing();
        if ($lapsing !== []) {
            $this->notice(
                sprintf(
                    /* translators: %s: comma-separated add-on names */
                    __('The license for %s has lapsed. Renew to keep receiving updates and security fixes.', 'gratora'),
                    $this->names($lapsing)
                )
            );

            return;
        }

    }

    /**
     * @param array<int,array{name:string}> $addons
     * @since 1.0.0
     */
    private function names(array $addons): string
    {
        return implode(', ', array_map(static fn (array $a): string => (string) $a['name'], $addons));
    }

    /**
     * Always a warning, never an error: an unlicensed add-on keeps running, it
     * just stops receiving updates. Red would overstate it.
     *
     * @since 1.0.0
     */
    private function notice(string $message): void
    {
        $style = 'border:1px solid #e5e7eb;border-left:3px solid #b54708;border-radius:8px;'
            . 'background:#fffaf5;color:#b54708;padding:11px 14px;'
            . "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;"
            . 'font-size:13px;line-height:1.45;';

        // The licence UI belongs to the licensing client vendored into each Pro
        // add-on. Core has no page of its own to send anyone to.
        $url = apply_filters('gratora.license.manage_url', '');
        $link = is_string($url) && $url !== ''
            ? sprintf(
                ' <a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Manage licenses', 'gratora')
            )
            : '';

        printf(
            '<div class="notice gratora-admin-notice" role="alert" style="%s"><strong>%s</strong> %s%s</div>',
            esc_attr($style),
            esc_html__('Gratora:', 'gratora'),
            esc_html($message),
            wp_kses_post($link)
        );
    }
}
