<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Settings\SettingsService;
use Dono\Campaigns\Styling\StylePresets;
use Dono\Campaigns\Styling\Tokens;
use Dono\Forms\FormService;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\License\LicenseService;

/**
 * Injects global Dono JS config into admin pages.
 *
 * @version 1.0.0
 */
final class AdminGlobals extends HookProvider
{
    public function __construct(private LicenseService $license)
    {
    }

    protected function actions(): array
    {
        return ['admin_print_scripts' => 'inject'];
    }

    public function inject(): void
    {
        if (! $this->isDonoAdminPage()) return;

        $currencyLocale = get_option('dono_currency_locale', []);
        $defaultCurrency = Money::defaultCurrency();

        $payload = [
            'rest'             => esc_url_raw(rest_url('dono/v1/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'pro'              => $this->license->snapshot(),
            'campaign_types'   => apply_filters('dono.campaign.types', ['standard' => __('Standard', 'dono')]),
            'campaign_type_notices' => apply_filters('dono.campaign.type_notices', []),
            'default_currency' => $defaultCurrency,
            'supported_currencies' => is_array($currencyLocale['supported_currencies'] ?? null)
                ? array_values($currencyLocale['supported_currencies'])
                : ['USD'],
            // Org-wide number format: admin JS reads from here; donor runtime gets it via shortcode config.
            'number_format' => Money::jsNumberFormat(),
            'wp' => [
                'site_name'    => (string) get_bloginfo('name'),
                'admin_email'  => (string) get_option('admin_email', ''),
                'home_url'     => esc_url_raw(home_url('/')),
                'dashboard_url' => esc_url_raw(admin_url('admin.php?page=dono')),
                'settings_url' => esc_url_raw(admin_url('admin.php?page=dono-settings')),
            ],
            'privacy_policy_url' => (function () {
                $opt = get_option('dono_privacy', []);
                $url = is_array($opt) ? trim((string) ($opt['privacy_policy_url'] ?? '')) : '';
                return $url !== '' ? esc_url_raw($url) : '';
            })(),
            'styling' => [
                'catalogue'  => Tokens::catalogue(),
                'groups'     => Tokens::groups(),
                'defaults'   => Tokens::defaults(),
                'presets'    => StylePresets::all(),
                // The built-ins as they ship, before any user edit is merged in.
                // Resetting a token in the brand editor restores the preset's
                // own value from here (Bold's navy, the Site theme's theme.json
                // accent), not the catalogue default that all presets share.
                'builtins'   => array_values(array_filter(array_merge(
                    StylePresets::builtins(),
                    [StylePresets::themePreset()]
                ))),
                'default_id' => StylePresets::defaultId(),
            ],
            'forms' => [
                'required_blocks' => FormService::requiredBlocks(),
            ],
            // Which merge tags each email template may safely offer. Sent from
            // PHP because the sender decides them, so the editor cannot drift
            // into advertising a tag nobody fills.
            'email_template_tags' => SettingsService::templateTags(),
            // Templates that ship outside core: the editor has no other way to
            // learn they exist.
            'email_template_meta' => SettingsService::templateMeta(),
        ];

        printf(
            '<script id="dono-admin-globals">window.dono = window.dono || {}; Object.assign(window.dono, %s);</script>',
            // JSON_HEX_TAG|JSON_HEX_AMP escape < > &, so a value containing
            // </script> (e.g. the site name) can't break out of the inline tag.
            wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        );
    }

    private function isDonoAdminPage(): bool
    {
        $page = is_string($_GET['page'] ?? null) ? (string) $_GET['page'] : '';

        // The dashboard's slug is the bare "dono"; every other screen is
        // "dono-something". Matching only the prefix left the one screen a new
        // install opens first without a config object, so its money rendered in
        // a default format while every other screen used the org's.
        return $page === 'dono' || strpos($page, 'dono-') === 0;
    }
}
