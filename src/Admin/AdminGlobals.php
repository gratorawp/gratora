<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Settings\SettingsService;
use FundKit\Campaigns\Styling\StylePresets;
use FundKit\Campaigns\Styling\Tokens;
use FundKit\Forms\FormService;
use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Hooks\HookProvider;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\License\LicenseService;

/**
 * Injects global FundKit JS config into admin pages.
 *
 * @since 1.0.0
 */
final class AdminGlobals extends HookProvider
{
    /** @since 1.0.0 */
    public function __construct(private LicenseService $license)
    {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return ['admin_enqueue_scripts' => 'inject'];
    }

    /** @since 1.0.0 */
    public function inject(): void
    {
        if (! $this->isFundKitAdminPage()) return;

        $currencyLocale = get_option('fundkit_currency_locale', []);
        $defaultCurrency = Money::defaultCurrency();

        $payload = [
            'rest'             => esc_url_raw(rest_url('fundkit/v1/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'pro'              => $this->license->snapshot(),
            'campaign_types'   => apply_filters('fundkit.campaign.types', ['standard' => __('Standard', 'fundkit-fundraising-campaigns')]),
            'campaign_type_notices' => apply_filters('fundkit.campaign.type_notices', []),
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
                'dashboard_url' => esc_url_raw(admin_url('admin.php?page=fundkit')),
                'settings_url' => esc_url_raw(admin_url('admin.php?page=fundkit-settings')),
                'campaigns_url' => esc_url_raw(admin_url('admin.php?page=fundkit-campaigns')),
            ],
            'privacy_policy_url' => (function () {
                $opt = get_option('fundkit_privacy', []);
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
            // Roles assigns capabilities, so only a full administrator may save
            // it. Without this the tab renders for a settings manager, who can
            // edit the grid and only learns it is refused on save.
            'can' => [
                'manage_options' => current_user_can('manage_options'),
                'export_donors'  => Capabilities::userCan('fundkit_export_donors'),
            ],
        ];

        // A src-less handle in the head, so every screen bundle that reads
        // window.fundkit finds it populated before it runs. All four HEX flags:
        // TAG and AMP escape < > & so a value holding a closing script tag
        // (the site name, say) cannot break out of the inline tag, and APOS
        // and QUOT leave nothing quote-shaped for a reader to reason about.
        $json = wp_json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        wp_register_script('fundkit-admin-globals', false, [], FUNDKIT_VERSION, false);
        wp_enqueue_script('fundkit-admin-globals');
        wp_add_inline_script(
            'fundkit-admin-globals',
            'window.fundkit = window.fundkit || {}; Object.assign(window.fundkit, ' . $json . ');'
        );
    }

    /** @since 1.0.0 */
    private function isFundKitAdminPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        // The dashboard's slug is the bare "fundkit"; every other screen is
        // "fundkit-something", so a prefix match alone would miss the dashboard.
        return $page === 'fundkit' || strpos($page, 'fundkit-') === 0;
    }
}
