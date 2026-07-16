<?php

declare(strict_types=1);

namespace Dono\Settings;

use Dono\Foundation\References\ReferenceGenerator;

/**
 * Reads and writes plugin settings, grouped by area (org-profile, gateways, email).
 * Each group maps to its own WP option. Add a group to GROUPS and REST + UI pick it up.
 *
 * @version 1.0.0
 */
final class SettingsService
{
    public const GROUPS = [
        'org-profile' => [
            'option'   => 'dono_org_profile',
            'defaults' => [
                'name'          => '',
                'legal_name'    => '',
                // address_lines is the canonical multi-line form read by
                // OrganizationPanel and the receipt renderer. The structured
                // siblings below are written by the Onboarding flow to round-trip
                // its own form state and are not consumed elsewhere.
                'address_lines' => [],
                'address_line1' => '',
                'address_line2' => '',
                'city'          => '',
                'postal_code'   => '',
                'state'         => '',
                'country'       => '',
                'tax_id'        => '',
                'vat_id'        => '',
                'email'         => '',
                'user_type'     => '',
                'cause'         => '',
            ],
        ],
        'currency-locale' => [
            'option'   => 'dono_currency_locale',
            'defaults' => [
                'default_currency'     => 'USD',
                'supported_currencies' => ['USD'],
                'locale'               => '',
                'format' => [
                    'decimal_places'  => 2,
                    'decimal_sep'     => '.',
                    'thousand_sep'    => ',',
                    'symbol_position' => 'before',
                ],
            ],
        ],
        'org-brand' => [
            'option'   => 'dono_org_brand',
            'defaults' => [
                // User-created presets; built-ins are merged on read by StylePresets::all().
                'presets'    => [],
                'default_id' => 'classic',
            ],
        ],
        'gateways' => [
            'option'   => 'dono_gateway_config',
            'defaults' => [
                // Org-wide test mode; per-form settings.test_mode also triggers it.
                'test_mode' => false,
                'stripe' => [
                    // `enabled` is derived from the Connect onboarding flow,
                    // not stored here. Webhook signing secrets are configured
                    // manually, per mode (Stripe issues a distinct secret for
                    // the test and live endpoints).
                    'webhook_secret_test' => '',
                    'webhook_secret_live' => '',
                ],
                'offline' => [
                    'enabled'      => true,
                    'instructions' => '',
                    'bank_details' => '',
                ],
            ],
        ],
        'privacy' => [
            'option'   => 'dono_privacy',
            'defaults' => [
                'privacy_policy_url'             => '',
                'retention_days_after_redaction' => 90,
                // Anonymise inactive donors after N years; 0 disables. Donation rows are kept.
                'donor_retention_years'          => 10,
                // Prune dono_events older than N days; 0 disables.
                'event_retention_days'           => 730,
                'anonymize_ips'                  => true,
                'always_anonymous_default'       => false,
                'allow_data_export'              => true,
                'allow_account_delete'           => true,
            ],
        ],
        'roles' => [
            'option'   => 'dono_roles',
            'defaults' => [
                'mapping' => [
                    'administrator' => [
                        'dono_view_donors', 'dono_edit_donors', 'dono_export_donors', 'dono_redact_donors',
                        'dono_view_donations', 'dono_edit_donations', 'dono_refund_donations', 'dono_resend_receipt',
                        'dono_view_reports', 'dono_manage_campaigns', 'dono_manage_forms', 'dono_manage_settings',
                    ],
                    'editor' => [
                        'dono_view_donors', 'dono_view_donations', 'dono_view_reports',
                    ],
                ],
            ],
        ],
        'advanced' => [
            'option'   => 'dono_advanced',
            'defaults' => [
                'debug_logging' => false,
            ],
        ],
        'consents' => [
            'option'   => 'dono_consents',
            'defaults' => [
                'purposes' => [
                    [
                        'key'         => 'newsletter',
                        'label'       => 'Newsletter',
                        'description' => 'Receive our monthly newsletter with stories and impact updates.',
                        'required'    => false,
                        'default'     => false,
                        'version'     => 1,
                    ],
                    [
                        'key'         => 'campaign_updates',
                        'label'       => 'Campaign updates',
                        'description' => 'Get updates on campaigns you have supported.',
                        'required'    => false,
                        'default'     => true,
                        'version'     => 1,
                    ],
                ],
            ],
        ],
        'receipts' => [
            'option'   => 'dono_receipt_settings',
            'defaults' => [
                'header_title'       => 'Donation receipt',
                'intro'              => '',
                'signoff'            => 'Thank you for your support, {donor_name}.',
                'footer_note'        => "This is a non-fiscal acknowledgement of receipt. Whether your donation is tax-deductible depends on your local jurisdiction and the recipient organisation's status. Keep this receipt for your records.",
                'show_tax_id'        => true,
                'show_donor_address' => false,
                'logo_attachment_id' => 0,
            ],
        ],
        // Reference/receipt sequential numbering. Shares ReferenceGenerator's
        // option + default shape so the panel and the generator stay in sync.
        'numbering' => [
            'option'   => ReferenceGenerator::OPTION_SETTINGS,
            'defaults' => ReferenceGenerator::DEFAULT_SETTINGS,
        ],
        'email' => [
            'option'   => 'dono_email_settings',
            'defaults' => [
                'from_name'  => '',
                'from_email' => '',
                'reply_to'   => '',
                'bcc_admin'  => false,
                'templates'  => [
                    'donation_receipt' => [
                        'enabled' => true,
                        'subject' => 'Thank you for your donation to {organisation_name}',
                        'body'    => "Hi {donor_first_name},\n\nThank you for your donation of {amount} to {organisation_name}.\n\nReference: {reference}\nReceipt number: {receipt_number}\n\nYour receipt is attached as a PDF. Keep it for your records.\n\nWith gratitude,\n{organisation_name}",
                    ],
                    'offline_instructions' => [
                        'enabled' => true,
                        'subject' => 'Payment instructions for your donation to {organisation_name}',
                        'body'    => "Hi {donor_name},\n\nThank you for choosing to support {campaign_title} with a donation of {amount}.\n\n{instructions}\n\nPlease transfer the amount using the reference {reference}. We will email your receipt as soon as the payment arrives.\n\n{bank_details}\n\nThanks,\n{organisation_name}",
                    ],
                    'donation_pending' => [
                        'enabled' => true,
                        'subject' => 'Your donation is processing',
                        'body'    => "Hi {donor_first_name},\n\nWe have received your donation of {amount}.\n\nReference: {reference}\n\nYour payment is being processed. Bank settlement can take a few business days; we will email your receipt the moment it clears.\n\nThanks,\n{organisation_name}",
                    ],
                    'donation_refunded' => [
                        'enabled' => true,
                        'subject' => 'Your donation has been refunded',
                        'body'    => "Hi {donor_name},\n\nWe have refunded your donation of {amount} to {campaign_title}. Funds should return to your card within 5 to 10 business days.\n\nIf this was a mistake or you have any questions, just reply to this email.\n\nThanks,\n{organisation_name}",
                    ],
                    'recurring_renewal' => [
                        'enabled' => true,
                        'subject' => 'Your recurring donation renewed',
                        'body'    => "Hi {donor_name},\n\nYour recurring donation of {amount} to {campaign_title} was renewed today. Receipt number: {receipt_number}.\n\nThank you for your continued support.\n\nThanks,\n{organisation_name}",
                    ],
                    'subscription_cancelled' => [
                        'enabled' => true,
                        'subject' => 'Your recurring donation has been cancelled',
                        'body'    => "Hi {donor_name},\n\nYour recurring donation of {amount} to {campaign_title} has been cancelled. No further charges will be made.\n\nThank you for the donations you made along the way.\n\nThanks,\n{organisation_name}",
                    ],
                    'magic_link' => [
                        'enabled' => true,
                        'subject' => 'Your sign-in link for {organisation_name}',
                        'body'    => "Hi {donor_name},\n\nOpen your donor portal:\n{portal_url}\n\nThis link works for 30 days. If you didn't request it, you can ignore this email.\n\nThanks,\n{organisation_name}",
                    ],
                ],
            ],
        ],
        // telemetry opt-in is captured during onboarding; no Settings panel
        // surfaces it. Kept here so the SettingsService recognises the group.
        'telemetry' => [
            'option'   => 'dono_telemetry',
            'defaults' => [
                'enabled'     => false,
                'opted_in_at' => null,
            ],
        ],
    ];

    /** @var array<string,mixed>|null memoised once per request (container instance lives one request) */
    private ?array $groupsCache = null;

    /**
     * Returns the group map, including any groups registered via `dono.settings.groups`.
     *
     * @return array<string,mixed>
     */
    public function groups(): array
    {
        if ($this->groupsCache === null) {
            $filtered = apply_filters('dono.settings.groups', self::GROUPS);
            $this->groupsCache = is_array($filtered) ? $filtered : self::GROUPS;
        }
        return $this->groupsCache;
    }

    /** Returns true when the named group is registered. */
    public function knows(string $group): bool
    {
        return array_key_exists($group, $this->groups());
    }

    /** @return array<string,mixed> */
    public function get(string $group): array
    {
        $cfg = $this->groups()[$group] ?? null;
        if (! $cfg) return [];
        $stored = get_option($cfg['option'], []);
        $defaults = $this->resolveDynamicDefaults($group, $cfg['defaults']);
        return $this->merge($defaults, is_array($stored) ? $stored : []);
    }

    /**
     * Fills in defaults that depend on runtime install state (blog name, admin email).
     *
     * @param array<string,mixed> $static
     * @return array<string,mixed>
     */
    private function resolveDynamicDefaults(string $group, array $static): array
    {
        if ($group !== 'email') return $static;

        // Use the blog name as sender name so donors see "{site name} <addr>" rather than bare "wordpress@host".
        if (($static['from_name'] ?? '') === '') {
            $blog = trim((string) get_bloginfo('name'));
            if ($blog !== '') $static['from_name'] = $blog;
        }

        // Only auto-fill from_email when admin-email domain matches site domain;
        // a mismatch causes SPF/DKIM failures, so empty is safer than a wrong default.
        if (($static['from_email'] ?? '') === '') {
            $admin = trim((string) get_option('admin_email'));
            if ($admin !== '' && is_email($admin)) {
                $siteHost  = (string) (wp_parse_url((string) home_url(), PHP_URL_HOST) ?: '');
                $atPos     = strrpos($admin, '@');
                $adminHost = $atPos !== false ? substr($admin, $atPos + 1) : '';
                if ($siteHost !== '' && $adminHost !== '' && strcasecmp($siteHost, $adminHost) === 0) {
                    $static['from_email'] = $admin;
                }
            }
        }

        return $static;
    }

    /**
     * Write a group's settings, merging with current values so callers may
     * send partial payloads. Returns the resulting array.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(string $group, array $input): array
    {
        $cfg = $this->groups()[$group] ?? null;
        if (! $cfg) return [];

        $current = $this->get($group);
        $next    = $this->merge($current, $input);

        // Replace mapping wholesale; deep-merge would prevent removing roles.
        if ($group === 'roles' && array_key_exists('mapping', $input)) {
            $next['mapping'] = is_array($input['mapping']) ? $input['mapping'] : [];
        }

        update_option($cfg['option'], $next, false);

        do_action('dono.settings.updated', $group, $next);
        return $next;
    }

    /**
     * Deep merge: scalars overwrite, assoc arrays recurse, sequential arrays replace wholesale.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && $this->isAssoc($v)) {
                $base[$k] = $this->merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }
}
