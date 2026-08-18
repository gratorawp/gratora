<?php

declare(strict_types=1);

namespace Dono\Settings;

use Dono\Analytics\ErrorLog;
use Dono\Currency\BaseCurrencyLock;
use Dono\Currency\BaseCurrencyLocked;
use Dono\Foundation\References\ReferenceGenerator;

/**
 * Reads and writes plugin settings, grouped by area (org-profile, gateways, email).
 * Each group maps to its own WP option. Add a group to GROUPS and REST + UI pick it up.
 *
 * @since 1.0.0
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
                    // `enabled` is declared at runtime for every registered
                    // gateway, see GatewayManager::declareSettings(). A gateway
                    // is offered when it is enabled AND can charge, so keys can
                    // stay on file while the method is paused. Webhook secrets
                    // are set per mode, since Stripe issues a distinct one for
                    // the test and live endpoints.
                    'webhook_secret_test' => '',
                    'webhook_secret_live' => '',
                ],
                'offline' => [
                    'enabled'      => true,
                    'instructions' => '',
                    'bank_details' => '',
                ],
                // Declared here rather than left to the runtime declaration,
                // because both register conditionally: PayPal only once keys
                // are connected, sandbox only while org-wide test mode is on.
                // A key nothing declares is dropped, so a restore onto a site
                // that has not re-entered its PayPal credentials yet lost the
                // org's "PayPal off" decision, and isOn() defaults a missing
                // flag to on, so the method came back switched on.
                'paypal'  => [],
                'sandbox' => [],
            ],
        ],
        'privacy' => [
            'option'   => 'dono_privacy',
            'defaults' => [
                'privacy_policy_url'             => '',
                'retention_days_after_redaction' => 90,
                // Off by default: erasure is the one thing here that destroys
                // donor PII on a schedule, with nobody asking for it. An org
                // opts into that in so many words or it does not happen.
                'erase_inactive_donors'          => false,
                // The window used once erasure is switched on: anonymize
                // inactive donors after N years. Donation rows are kept.
                'donor_retention_years'          => 7,
                // Prune dono_events older than N days; 0 disables.
                'event_retention_days'           => 730,
                'anonymize_ips'                  => true,
                // Off by default: a Gravatar request carries a hash of the
                // donor's address to a third party, from the visitor's browser,
                // on a public page. That is the org's call to make, not ours.
                'gravatar_avatars'               => false,
                'always_anonymous_default'       => false,
                'allow_data_export'              => true,
                'allow_account_delete'           => true,
            ],
        ],
        'roles' => [
            'option'   => 'dono_roles',
            // Administrator only. The Roles screen shows this mapping as
            // granted, and CoreModule seeds it into real capabilities on the
            // first admin load, so a role listed here holds what the screen
            // says it holds. Every other role starts with nothing: donor
            // records carry decrypted contact details, and who reads them is
            // the org's decision to make on this screen.
            'defaults' => [
                'mapping' => [
                    'administrator' => [
                        'dono_view_donors', 'dono_edit_donors', 'dono_export_donors', 'dono_redact_donors',
                        'dono_view_donations', 'dono_edit_donations', 'dono_refund_donations', 'dono_resend_receipt',
                        'dono_view_reports', 'dono_manage_campaigns', 'dono_manage_forms', 'dono_manage_settings',
                    ],
                ],
            ],
        ],
        'consents' => [
            'option'   => 'dono_consents',
            // Empty on purpose. A consent purpose names something the
            // organization actually does, and we do not know what that is.
            // Shipping a "Newsletter" purpose puts a permanent "No response"
            // on every donor profile and offers donors a subscription that may
            // not exist, which is a record of consent to nothing.
            'defaults' => [
                'purposes' => [],
            ],
        ],
        'receipts' => [
            'option'   => 'dono_receipt_settings',
            'defaults' => [
                'header_title'       => 'Donation receipt',
                'intro'              => '',
                'signoff'            => 'Thank you for your support, {donor_name}.',
                'footer_note'        => "This is a non-fiscal acknowledgement of receipt. Whether your donation is tax-deductible depends on your local jurisdiction and the recipient organization's status. Keep this receipt for your records.",
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
                // Subjects/bodies are filled at runtime by emailTemplateDefaults()
                // via resolveDynamicDefaults: const expressions can't call __(),
                // so keeping them here would make every transactional email
                // untranslatable regardless of the installed locale.
                'templates'  => [],
            ],
        ],
    ];

    /** @var array<string,mixed>|null memoised once per request (container instance lives one request) */
    private ?array $groupsCache = null;

    /**
     * The group map, including any groups registered via `dono.settings.groups`.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function groups(): array
    {
        if ($this->groupsCache === null) {
            $filtered = apply_filters('dono.settings.groups', self::GROUPS);
            $this->groupsCache = is_array($filtered) ? $filtered : self::GROUPS;
        }
        return $this->groupsCache;
    }

    /** @since 1.0.0 */
    public function knows(string $group): bool
    {
        return array_key_exists($group, $this->groups());
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function get(string $group): array
    {
        $cfg = $this->groups()[$group] ?? null;
        if (! $cfg) return [];
        $stored = get_option($cfg['option'], []);
        $defaults = $this->resolveDynamicDefaults($group, $cfg['defaults']);
        return $this->merge($defaults, is_array($stored) ? $stored : []);
    }

    /**
     * Transactional email template defaults. Built here (not in the const) so
     * subjects and bodies pass through __() and are translatable; the stored
     * option overlays these in get() when an admin customizes a template.
     *
     * @return array<string,array{enabled:bool,subject:string,body:string}>
     *
     * @since 1.0.0
     */
    private function emailTemplateDefaults(): array
    {
        return [
            'donation_receipt' => [
                'enabled' => true,
                'subject' => __('Thank you for your donation to {organisation_name}', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nThank you for your donation of {amount} to {organisation_name}.\n\nReference: {reference}\nReceipt number: {receipt_number}\n\nYour receipt is attached as a PDF. Keep it for your records.\n\nWith gratitude,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'donation_first' => [
                'enabled' => true,
                'subject' => __('Thank you for your first donation to {organisation_name}', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nThank you for making your first donation to {organisation_name}. Your support means a great deal, and we are grateful to have you with us.\n\nWe will keep you posted on the difference it makes.\n\nWith gratitude,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'offline_instructions' => [
                'enabled' => true,
                'subject' => __('Payment instructions for your donation to {organisation_name}', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_name},\n\nThank you for choosing to support {campaign_title} with a donation of {amount}.\n\n{instructions}\n\nPlease transfer the amount using the reference {reference}. We will email your receipt as soon as the payment arrives.\n\n{bank_details}\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'donation_pending' => [
                'enabled' => true,
                'subject' => __('Your donation is processing', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nWe have received your donation of {amount}.\n\nReference: {reference}\n\nYour payment is being processed. Bank settlement can take a few business days; we will email your receipt the moment it clears.\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'donation_refunded' => [
                'enabled' => true,
                'subject' => __('Your donation has been refunded', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_name},\n\nWe have refunded your donation of {amount} to {campaign_title}. Funds should return to your card within 5 to 10 business days.\n\nIf this was a mistake or you have any questions, just reply to this email.\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'recurring_renewal' => [
                'enabled' => true,
                'subject' => __('Your recurring donation renewed', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_name},\n\nYour recurring donation of {amount} to {campaign_title} was renewed today.\n\nReference: {reference}\n\nThank you for your continued support.\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'subscription_payment_failed' => [
                'enabled' => true,
                'subject' => __("Your donation couldn't be taken this month", 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nWe tried to collect your recurring donation of {amount} to {campaign_title} today and your bank declined it. This usually means a card has expired or been replaced.\n\nNothing has been charged and your donation is still set up. You can update your card here:\n{portal_url}\n\nIf you would rather stop the donation, that is completely fine, and you can do that from the same page.\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'subscription_cancelled' => [
                'enabled' => true,
                'subject' => __('Your recurring donation has been cancelled', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_name},\n\nYour recurring donation of {amount} to {campaign_title} has been cancelled. No further charges will be made.\n\nThank you for the donations you made along the way.\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            // Sent only when someone at the organization changes a plan on the
            // donor's behalf. A donor changing their own donation is looking at
            // the screen that did it and gets nothing.
            'recurring_amount_changed' => [
                'enabled' => true,
                'subject' => __('Your recurring donation amount has changed', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nYour recurring donation to {campaign_title} has been changed from {old_amount} to {amount}, starting with your next payment.\n\nIf that is not what you expected, you can change it back or stop the donation here:\n{portal_url}\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'recurring_paused' => [
                'enabled' => true,
                'subject' => __('Your recurring donation is paused', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nYour recurring donation of {amount} to {campaign_title} has been paused. Nothing will be charged until it restarts on {resumes_at}.\n\nYou can restart it sooner, or stop it altogether, here:\n{portal_url}\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'recurring_resumed' => [
                'enabled' => true,
                'subject' => __('Your recurring donation has restarted', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nYour recurring donation of {amount} to {campaign_title} has restarted. Your next payment is due on {next_payment_at}.\n\nYou can manage it any time here:\n{portal_url}\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'recurring_skipped' => [
                'enabled' => true,
                'subject' => __('Your next donation has been skipped', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_first_name},\n\nYour next recurring donation of {amount} to {campaign_title} has been skipped. Nothing will be charged this time, and the donation continues on {next_payment_at}.\n\nYou can manage it any time here:\n{portal_url}\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
            'magic_link' => [
                'enabled' => true,
                'subject' => __('Your sign-in link for {organisation_name}', 'dono-fundraising-platform'),
                'body'    => __("Hi {donor_name},\n\nOpen your donor portal:\n{portal_url}\n\nThis link works for {link_expiry} and can only be used once. If you didn't request it, you can ignore this email.\n\nThanks,\n{organisation_name}", 'dono-fundraising-platform'),
            ],
        ];
    }

    /**
     * The merge tags each template's sender actually passes.
     *
     * Lives here, next to the templates, because the admin editor offers these
     * to an author as safe to insert. Mailer::interpolate only replaces tags it
     * is given, so an advertised tag the sender never fills does not vanish, it
     * reaches the donor as literal braces. Offering one is therefore a promise,
     * and the promise is kept by whoever calls sendTemplate.
     *
     * @return array<string, list<string>>
     *
     * @since 1.0.0
     */
    public static function templateTags(): array
    {
        $donor    = ['donor_first_name', 'donor_name', 'organisation_name'];
        $donation = array_merge($donor, ['amount', 'campaign_title']);

        return (array) apply_filters('dono.email.template_tags', [
            'donation_receipt'            => array_merge($donation, ['receipt_number', 'reference', 'download_url']),
            'donation_first'              => $donor,
            'donation_pending'            => array_merge($donation, ['reference']),
            'donation_refunded'           => array_merge($donation, ['reference']),
            'offline_instructions'        => array_merge($donation, ['reference', 'bank_details', 'instructions']),
            // No receipt_number: the renewal notice goes out before the receipt
            // row is issued, so the tag could only ever resolve to nothing.
            'recurring_renewal'           => array_merge($donation, ['reference']),
            'subscription_payment_failed' => array_merge($donation, ['portal_url']),
            'subscription_cancelled'      => $donation,
            'recurring_amount_changed'    => array_merge($donation, ['old_amount', 'portal_url']),
            'recurring_paused'            => array_merge($donation, ['resumes_at', 'portal_url']),
            'recurring_resumed'           => array_merge($donation, ['next_payment_at', 'portal_url']),
            'recurring_skipped'           => array_merge($donation, ['next_payment_at', 'portal_url']),
            'magic_link'                  => ['donor_name', 'organisation_name', 'portal_url', 'link_expiry'],
        ]);
    }

    /**
     * Label, description and recipient for templates that ship outside core.
     *
     * The settings editor lists the templates it has been told about, so a
     * template an add-on registers and never describes is stored, sent, and
     * invisible to the admin whose name is on it. Core describes its own in the
     * editor bundle, so this starts empty.
     *
     * @return list<array{id:string,label:string,desc?:string,recipient?:string}>
     *
     * @since 1.0.0
     */
    public static function templateMeta(): array
    {
        return array_values((array) apply_filters('dono.email.template_meta', []));
    }

    /**
     * Fills in defaults that depend on runtime install state (blog name, admin email).
     *
     * @param array<string,mixed> $static
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function resolveDynamicDefaults(string $group, array $static): array
    {
        if ($group !== 'email') return $static;

        // Translatable template defaults (see the const note). Merge core
        // defaults UNDER any templates a filter already contributed, so an
        // add-on registering its own templates (dono.settings.groups) cannot
        // displace the core set - otherwise activating an add-on silently
        // drops core transactional emails (receipts, magic link). The stored
        // option overlays both in get(), so a customized template still wins.
        $static['templates'] = array_merge(
            $this->emailTemplateDefaults(),
            is_array($static['templates'] ?? null) ? $static['templates'] : []
        );

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
     *
     * @throws BaseCurrencyLocked when the write would re-denominate recorded money
     *
     * @since 1.0.0
     */
    public function update(string $group, array $input): array
    {
        $cfg = $this->groups()[$group] ?? null;
        if (! $cfg) return [];

        $current = $this->get($group);

        // Here rather than in the REST controller: the settings.update command,
        // the CLI and any add-on writer all land on this method, and an
        // invariant about recorded money cannot depend on which door was used.
        if ($group === 'currency-locale') {
            BaseCurrencyLock::assert($input, $current);
        }

        $next    = $this->merge($current, $this->accept($group, $cfg, $input));

        // Replace mapping wholesale; deep-merge would prevent removing roles.
        if ($group === 'roles' && array_key_exists('mapping', $input)) {
            $next['mapping'] = is_array($input['mapping']) ? $input['mapping'] : [];
        }

        update_option($cfg['option'], $next, false);

        // The values as they were are handed along too: a listener that has to
        // act on a setting being switched on, rather than on every save of the
        // group it lives in, has no other way to tell the two apart.
        do_action('dono.settings.updated', $group, $next, $current);
        return $next;
    }


    /**
     * Keep only what this group declares, at the type it declares it.
     *
     * A key absent from the defaults would persist as a setting nothing reads,
     * and a string landing where an int belongs makes a retention window saved
     * as "" compare as zero everywhere.
     *
     * Top level only, deliberately. roles.mapping is role => capabilities and
     * numbering.prefixes is scope => prefix; both have keys core cannot know,
     * so recursing would throw away exactly the data the screen is editing.
     * Add-ons registering a group through dono.settings.groups are covered by
     * the same rule, since a group has to declare its defaults to work at all.
     *
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function accept(string $group, array $cfg, array $input): array
    {
        $defaults = is_array($cfg['defaults'] ?? null) ? $cfg['defaults'] : [];

        $kept     = [];
        $rejected = [];

        foreach ($input as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                $rejected[] = $key;
                continue;
            }

            $default = $defaults[$key];

            // A shape mismatch is a bug in the caller, not a value to coerce:
            // casting an array to int, or a scalar to array, produces nonsense
            // that then looks like a saved setting.
            if (is_array($default) !== is_array($value)) {
                $rejected[] = $key;
                continue;
            }

            $kept[$key] = match (true) {
                is_array($default) => $value,
                // A null default is "nothing yet", not a type. Coercing a
                // timestamp against it would turn it into a string.
                is_null($default)  => $value,
                is_bool($default)  => (bool) $value,
                is_int($default)   => (int) $value,
                is_float($default) => (float) $value,
                default            => is_scalar($value) ? (string) $value : '',
            };
        }

        if ($rejected !== []) {
            // Not silent. Dropping a key without a word is how a setting stops
            // saving and nobody finds out until someone asks why it reverted.
            ErrorLog::record('settings', sprintf(
                'Group %s rejected unknown or mistyped keys: %s',
                $group,
                implode(', ', $rejected)
            ));
            do_action('dono.settings.rejected', $group, $rejected);
        }

        return $kept;
    }

    /**
     * Deep merge: scalars overwrite, assoc arrays recurse, sequential arrays replace wholesale.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     *
     * @since 1.0.0
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

    /** @since 1.0.0 */
    private function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }
}
