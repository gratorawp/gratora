<?php

declare(strict_types=1);

namespace Gratora\Foundation\Auth;

/** @since 1.0.0 */
final class Capabilities
{
    /** Umbrella cap: "can reach the Gratora admin area at all" (menu + base gate). */
    public const MANAGE = 'manage_gratora';

    public const ALL = [
        'gratora_view_donors',
        'gratora_edit_donors',
        'gratora_export_donors',
        'gratora_redact_donors',
        'gratora_view_donations',
        'gratora_edit_donations',
        'gratora_refund_donations',
        'gratora_resend_receipt',
        'gratora_view_reports',
        'gratora_manage_campaigns',
        'gratora_manage_forms',
        'gratora_manage_settings',
    ];

    public const GROUPS = [
        'Donors'    => ['gratora_view_donors', 'gratora_edit_donors', 'gratora_export_donors', 'gratora_redact_donors'],
        'Donations' => ['gratora_view_donations', 'gratora_edit_donations', 'gratora_refund_donations', 'gratora_resend_receipt'],
        'Reports'   => ['gratora_view_reports'],
        'Setup'     => ['gratora_manage_campaigns', 'gratora_manage_forms', 'gratora_manage_settings'],
    ];

    /**
     * Display wording for each capability, so it cannot live in a const: a
     * const cannot call __(), and the Roles grid is the one settings tab a
     * translated site would otherwise read in English.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private static function coreLabels(): array
    {
        return [
            'gratora_view_donors'      => __('View donors', 'gratora'),
            'gratora_edit_donors'      => __('Edit donor records', 'gratora'),
            'gratora_export_donors'    => __('Export donor list (CSV)', 'gratora'),
            'gratora_redact_donors'    => __('Redact donors (GDPR)', 'gratora'),
            'gratora_view_donations'   => __('View donations', 'gratora'),
            'gratora_edit_donations'   => __('Edit donations (notes)', 'gratora'),
            'gratora_refund_donations' => __('Change what is charged (refund, mark paid, record by hand, change a recurring plan)', 'gratora'),
            'gratora_resend_receipt'   => __('Resend receipts', 'gratora'),
            'gratora_view_reports'     => __('View dashboards & reports', 'gratora'),
            'gratora_manage_campaigns' => __('Manage campaigns', 'gratora'),
            'gratora_manage_forms'     => __('Manage donation forms', 'gratora'),
            'gratora_manage_settings'  => __('Manage settings', 'gratora'),
        ];
    }

    /**
     * The capability maps with add-on registrations applied.
     *
     * @return array{all:array<int,string>,groups:array<string,array<int,string>>,labels:array<string,string>}
     * @since 1.0.0
     */
    private static function maps(): array
    {
        $maps = apply_filters('gratora.capabilities', [
            'all'    => self::ALL,
            'groups' => self::GROUPS,
            'labels' => self::coreLabels(),
        ]);
        if (! is_array($maps)) {
            $maps = [];
        }
        return [
            'all'    => array_values(array_unique((array) ($maps['all'] ?? self::ALL))),
            'groups' => (array) ($maps['groups'] ?? self::GROUPS),
            'labels' => (array) ($maps['labels'] ?? self::coreLabels()),
        ];
    }

    /**
     * @return array<int,string>
     * @since 1.0.0
     */
    public static function all(): array
    {
        return self::maps()['all'];
    }

    /**
     * @return array<string,array<int,string>>
     * @since 1.0.0
     */
    public static function groups(): array
    {
        return self::maps()['groups'];
    }

    /**
     * @return array<string,string>
     * @since 1.0.0
     */
    public static function labels(): array
    {
        return self::maps()['labels'];
    }

    /**
     * Per-endpoint gate for the admin REST controllers. WP super-admins
     * (manage_options) always pass so a default administrator never loses
     * access to the admin UI; otherwise the specific granular cap is required,
     * which is how a custom role gets scoped access.
     *
     * @since 1.0.0
     */
    public static function userCan(string $cap): bool
    {
        return current_user_can('manage_options') || current_user_can($cap);
    }

    /**
     * True for anyone who may reach the Gratora admin area at all (menu/base gate).
     *
     * @since 1.0.0
     */
    public static function canAccessAdmin(): bool
    {
        if (current_user_can('manage_options') || current_user_can(self::MANAGE)) {
            return true;
        }
        foreach (self::all() as $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Virtual menu meta-caps: WP menus take one capability string, so each
     * gratora_access_* grants on manage_options or the area's granular cap. REST still
     * enforces the granular caps, so menu visibility never widens actual access.
     *
     * @var array<string,string> menu meta-cap => the granular cap it maps to
     */
    /**
     * The roles the Roles screen can edit, and so the only roles whose absence
     * from the mapping means revoke. A role granted Gratora capabilities by a
     * role editor, a theme or an add-on is invisible to that screen, and had
     * them stripped on the next save of it.
     *
     * @var list<string>
     */
    public const MANAGED_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];

    public const MENU_AREAS = [
        'gratora_access_reports'   => 'gratora_view_reports',
        'gratora_access_campaigns' => 'gratora_manage_campaigns',
        'gratora_access_donations' => 'gratora_view_donations',
        'gratora_access_donors'    => 'gratora_view_donors',
        'gratora_access_forms'     => 'gratora_manage_forms',
        'gratora_access_settings'  => 'gratora_manage_settings',
    ];

    /**
     * Grant virtual menu capabilities; see MENU_AREAS.
     *
     * @since 1.0.0
     */
    public static function grantMetaCaps(array $allcaps): array
    {
        $super   = ! empty($allcaps['manage_options']);
        $anyArea = false;

        foreach (self::MENU_AREAS as $virtual => $real) {
            if ($super || ! empty($allcaps[$real])) {
                $allcaps[$virtual] = true;
                $anyArea = true;
            }
            // An administrator holds each everyday area cap for real, so command
            // dispatch - which requires the granular cap, unlike the lenient
            // Capabilities::userCan the admin UI uses - lets them do what the UI
            // already lets them do. Sensitive caps (refunds, PII edits/exports,
            // redaction) are not menu areas, so admins never gain them implicitly.
            if ($super) {
                $allcaps[$real] = true;
            }
        }

        // Add-ons declare the everyday caps their command packs need so a
        // default administrator can drive them out of the box (the assistant
        // dispatches with the strict granular check, not the lenient userCan).
        // Add-ons keep sensitive caps off this list, so those stay explicit.
        if ($super) {
            foreach ((array) apply_filters('gratora.capabilities.admin_caps', []) as $cap) {
                if (is_string($cap) && $cap !== '') {
                    $allcaps[$cap] = true;
                }
            }
        }

        if ($super || ! empty($allcaps[self::MANAGE]) || $anyArea) {
            $allcaps['gratora_access'] = true;
        }

        return $allcaps;
    }

    /**
     * Apply a role-to-caps mapping to the roles the mapping names and the roles
     * the Roles screen edits. A role that receives at least one granular cap
     * also gets the MANAGE umbrella so it can see the Gratora menu; the
     * administrator always keeps MANAGE. Runs on activation and whenever the
     * roles mapping is saved.
     *
     * @param array<string, list<string>> $mapping
     * @param list<string>                $alsoGovern extra role slugs to treat as described
     *
     * @since 1.0.0
     */
    public static function applyMapping(array $mapping, array $alsoGovern = []): void
    {
        $allCaps  = self::all();
        // A role nobody wrote into the mapping is a role this mapping says
        // nothing about, so it is left alone rather than stripped.
        $governed = array_unique(array_merge(self::MANAGED_ROLES, array_keys($mapping), $alsoGovern));

        foreach (wp_roles()->role_objects as $slug => $role) {
            if (! in_array($slug, $governed, true)) {
                continue;
            }

            $granted = is_array($mapping[$slug] ?? null) ? $mapping[$slug] : [];
            $hasAny  = false;
            foreach ($allCaps as $cap) {
                if (in_array($cap, $granted, true)) {
                    $role->add_cap($cap);
                    $hasAny = true;
                } else {
                    $role->remove_cap($cap);
                }
            }
            if ($slug === 'administrator' || $hasAny) {
                $role->add_cap(self::MANAGE);
            } else {
                $role->remove_cap(self::MANAGE);
            }
        }
    }

    /** @since 1.0.0 */
    public static function currentMapping(): array
    {
        $stored = get_option('gratora_roles', []);
        $map    = is_array($stored['mapping'] ?? null) ? $stored['mapping'] : [];
        return $map;
    }
}
