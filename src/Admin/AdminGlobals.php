<?php

declare(strict_types=1);

namespace Gratora\Admin;

use Gratora\Campaigns\Styling\StylePresets;
use Gratora\Campaigns\Styling\Tokens;
use Gratora\Currency\CurrencyFormats;
use Gratora\Forms\FormService;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Hooks\HookProvider;
use Gratora\Foundation\Http\ClientIp;
use Gratora\Donors\DonorType;
use Gratora\Foundation\License\LicenseService;
use Gratora\Recurring\PlanStatus;
use Gratora\Settings\SettingsService;

/** @since 1.0.0 */
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
        // Check capabilities before exposing configuration through a user-controlled page slug.
        if (! CurrentPage::isGratora() || ! Capabilities::canAccessAdmin()) return;

        $currencyLocale = get_option('gratora_currency_locale', []);
        $defaultCurrency = Money::defaultCurrency();

        $payload = [
            'rest'             => esc_url_raw(rest_url('gratora/v1/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'pro'              => $this->license->snapshot(),
            'campaign_types'   => apply_filters('gratora.campaign.types', ['standard' => __('Standard', 'gratora-donation-platform')]),
            'campaign_type_notices' => apply_filters('gratora.campaign.type_notices', []),
            'default_currency' => $defaultCurrency,
            'supported_currencies' => is_array($currencyLocale['supported_currencies'] ?? null)
                ? array_values($currencyLocale['supported_currencies'])
                : ['USD'],
            // Org-wide number format: admin JS reads from here; donor runtime gets it via shortcode config.
            'number_format' => Money::jsNumberFormat(),
            'currency_formats' => CurrencyFormats::all(),
            'wp' => [
                'site_name'    => (string) get_bloginfo('name'),
                'admin_email'  => (string) get_option('admin_email', ''),
                'home_url'     => esc_url_raw(home_url('/')),
                'dashboard_url' => esc_url_raw(admin_url('admin.php?page=gratora')),
                'settings_url' => esc_url_raw(admin_url('admin.php?page=gratora-settings')),
                'campaigns_url' => esc_url_raw(admin_url('admin.php?page=gratora-campaigns')),
            ],
            'privacy_policy_url' => (function () {
                $opt = get_option('gratora_privacy', []);
                $url = is_array($opt) ? trim((string) ($opt['privacy_policy_url'] ?? '')) : '';
                return $url !== '' ? esc_url_raw($url) : '';
            })(),
            'styling' => [
                'catalogue'  => Tokens::catalogue(),
                'groups'     => Tokens::groups(),
                'defaults'   => Tokens::defaults(),
                'presets'    => StylePresets::all(),
                // Keep unmodified preset values so token resets restore the selected preset.
                'builtins'   => array_values(array_filter(array_merge(
                    StylePresets::builtins(),
                    [StylePresets::themePreset()]
                ))),
                'default_id' => StylePresets::defaultId(),
            ],
            'forms' => [
                'required_blocks' => FormService::requiredBlocks(),
            ],
            // Use the sender’s merge-tag registry to keep editor suggestions valid.
            'email_template_tags' => SettingsService::templateTags(),
            // Expose add-on email templates to the editor.
            'email_template_meta' => SettingsService::templateMeta(),
            // Match REST permissions, including the manage_options bypass. JS keys omit
            // gratora_.
            'can' => self::capabilities(),
            // The plan lifecycle in words, so a screen filtering or labelling one
            // is not keeping its own copy of the statuses this side writes.
            'plan_statuses' => PlanStatus::all(),
            'donor_types'   => DonorType::all(),
            // Detect proxy defaults for the spam-protection settings.
            'detectedProxy' => ClientIp::undeclaredProxy(),
        ];

        // Populate window.gratora before screen bundles run. HEX flags prevent inline-script
        // breakout.
        $json = wp_json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        wp_register_script('gratora-admin-globals', false, [], GRATORA_VERSION, false);
        wp_enqueue_script('gratora-admin-globals');
        wp_add_inline_script(
            'gratora-admin-globals',
            'window.gratora = window.gratora || {}; Object.assign(window.gratora, ' . $json . ');'
        );
    }

    /**
     * @return array<string, bool>
     *
     * @since 1.0.0
     */
    private static function capabilities(): array
    {
        // Only full administrators may assign roles, matching the save route.
        $can = ['manage_options' => current_user_can('manage_options')];

        foreach (Capabilities::all() as $cap) {
            $key = str_starts_with($cap, 'gratora_') ? substr($cap, strlen('gratora_')) : $cap;
            $can[$key] = Capabilities::userCan($cap);
        }

        return $can;
    }
}
