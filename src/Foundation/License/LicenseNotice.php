<?php

declare(strict_types=1);

namespace Gratora\Foundation\License;

use Gratora\Admin\CurrentPage;

/**
 * Tells an admin on Gratora's own screens that a license needs attention.
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
        if (! current_user_can('manage_options') || ! CurrentPage::isGratora()) {
            return;
        }
        // Already on a screen that says all of this.
        if (CurrentPage::slug() === 'gratora-settings' || $this->onManageScreen()) {
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
                    __('The license for %s has lapsed. Renew to keep receiving updates and security fixes.', 'gratora-donation-platform'),
                    $this->names($lapsing)
                )
            );

            return;
        }

        // Nothing was checked, which is what no key looks like: not a pass.
        $unchecked = array_filter($addons, static fn (array $a): bool => $a['status'] === 'unknown');
        if (count($unchecked) === count($addons)) {
            $this->notice(
                __('Your add-ons are not linked to a license key', 'gratora-donation-platform') . '. '
                . __('They keep running, but they will not receive updates or security fixes.', 'gratora-donation-platform')
            );
        }
    }

    /**
     * The licensing client's own screen, which lists every add-on's status.
     *
     * @since 1.1.0
     */
    private function onManageScreen(): bool
    {
        parse_str((string) wp_parse_url($this->manageUrl(), PHP_URL_QUERY), $query);
        $page = is_string($query['page'] ?? null) ? sanitize_key($query['page']) : '';

        return $page !== '' && $page === CurrentPage::slug();
    }

    /**
     * The license UI belongs to the licensing client vendored into each Pro
     * add-on. Core has no page of its own to send anyone to.
     *
     * @since 1.1.0
     */
    private function manageUrl(): string
    {
        $url = apply_filters('gratora.license.manage_url', '');

        return is_string($url) ? $url : '';
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

        $url  = $this->manageUrl();
        $link = $url !== ''
            ? sprintf(
                ' <a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Manage licenses', 'gratora-donation-platform')
            )
            : '';

        printf(
            '<div class="notice gratora-admin-notice" role="alert" style="%s"><strong>%s</strong> %s%s</div>',
            esc_attr($style),
            esc_html__('Gratora:', 'gratora-donation-platform'),
            esc_html($message),
            wp_kses_post($link)
        );
    }
}
