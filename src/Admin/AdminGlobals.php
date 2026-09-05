<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Settings\SettingsService;
use FundKit\Campaigns\Styling\StylePresets;
use FundKit\Currency\CurrencyFormats;
use FundKit\Campaigns\Styling\Tokens;
use FundKit\Forms\FormService;
use FundKit\Foundation\Auth\Capabilities;
use FundKit\Foundation\Http\ClientIp;
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
            'campaign_types'   => apply_filters('fundkit.campaign.types', ['standard' => __('Standard', 'fundraising-toolkit')]),
            'campaign_type_notices' => apply_filters('fundkit.campaign.type_notices', []),
            'default_currency' => $defaultCurrency,
            'supported_currencies' => is_array($currencyLocale['supported_currencies'] ?? null)
                ? array_values($currencyLocale['supported_currencies'])
                : ['USD'],
            // Org-wide number format: admin JS reads from here; donor runtime gets it via shortcode config.
            'number_format' => Money::jsNumberFormat(),
            // Presets the currency settings panel fills the format from.
            'currency_formats' => CurrencyFormats::all(),
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
            // What this reader may do, so a screen offers what its routes will
            // accept rather than what its data happens to allow. Read through
            // Capabilities::userCan, not current_user_can, so the answer here
            // is the same one the REST gate gives, manage_options bypass and
            // all. Keys drop the fundkit_ prefix; see assets/admin/_shared/caps.
            'can' => self::capabilities(),
            // What is in front of this site, if the site has not said. The
            // Spam protection screen turns this into one button, because the
            // people who need the setting are not the people who know what a
            // CIDR range is, and the ranges are ours to know rather than
            // theirs to look up.
            'detectedProxy' => ClientIp::undeclaredProxy(),
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

    /**
     * @return array<string, bool>
     *
     * @since 1.0.0
     */
    private static function capabilities(): array
    {
        // Roles assigns capabilities, so only a full administrator may save it.
        // Without this the tab renders for a settings manager, who can edit the
        // grid and only learns it is refused on save.
        $can = ['manage_options' => current_user_can('manage_options')];

        foreach (Capabilities::all() as $cap) {
            $key = str_starts_with($cap, 'fundkit_') ? substr($cap, strlen('fundkit_')) : $cap;
            $can[$key] = Capabilities::userCan($cap);
        }

        return $can;
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
