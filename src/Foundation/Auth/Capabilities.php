<?php

declare(strict_types=1);

namespace GiveFlow\Foundation\Auth;

/**
 * All plugin capability constants and role-mapping helpers.
 *
 * @since 1.0.0
 */
final class Capabilities
{
    /** Umbrella cap: "can reach the GiveFlow admin area at all" (menu + base gate). */
    public const MANAGE = 'manage_giveflow';

    public const ALL = [
        'giveflow_view_donors',
        'giveflow_edit_donors',
        'giveflow_export_donors',
        'giveflow_redact_donors',
        'giveflow_view_donations',
        'giveflow_edit_donations',
        'giveflow_refund_donations',
        'giveflow_resend_receipt',
        'giveflow_view_reports',
        'giveflow_manage_campaigns',
        'giveflow_manage_forms',
        'giveflow_manage_settings',
    ];

    public const GROUPS = [
        'Donors'    => ['giveflow_view_donors', 'giveflow_edit_donors', 'giveflow_export_donors', 'giveflow_redact_donors'],
        'Donations' => ['giveflow_view_donations', 'giveflow_edit_donations', 'giveflow_refund_donations', 'giveflow_resend_receipt'],
        'Reports'   => ['giveflow_view_reports'],
        'Setup'     => ['giveflow_manage_campaigns', 'giveflow_manage_forms', 'giveflow_manage_settings'],
    ];

    public const LABELS = [
        'giveflow_view_donors'      => 'View donors',
        'giveflow_edit_donors'      => 'Edit donor records',
        'giveflow_export_donors'    => 'Export donor list (CSV)',
        'giveflow_redact_donors'    => 'Redact donors (GDPR)',
        'giveflow_view_donations'   => 'View donations',
        'giveflow_edit_donations'   => 'Edit donations (notes)',
        'giveflow_refund_donations' => 'Change what is charged (refund, mark paid, record by hand, change a recurring plan)',
        'giveflow_resend_receipt'   => 'Resend receipts',
        'giveflow_view_reports'     => 'View dashboards & reports',
        'giveflow_manage_campaigns' => 'Manage campaigns',
        'giveflow_manage_forms'     => 'Manage donation forms',
        'giveflow_manage_settings'  => 'Manage settings',
    ];

    /**
     * The capability maps with add-on registrations applied.
     *
     * @return array{all:array<int,string>,groups:array<string,array<int,string>>,labels:array<string,string>}
     * @since 1.0.0
     */
    private static function maps(): array
    {
        $maps = apply_filters('giveflow.capabilities', [
            'all'    => self::ALL,
            'groups' => self::GROUPS,
            'labels' => self::LABELS,
        ]);
        if (! is_array($maps)) {
            $maps = [];
        }
        return [
            'all'    => array_values(array_unique((array) ($maps['all'] ?? self::ALL))),
            'groups' => (array) ($maps['groups'] ?? self::GROUPS),
            'labels' => (array) ($maps['labels'] ?? self::LABELS),
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
     * True for anyone who may reach the GiveFlow admin area at all (menu/base gate).
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
     * giveflow_access_* grants on manage_options or the area's granular cap. REST still
     * enforces the granular caps, so menu visibility never widens actual access.
     *
     * @var array<string,string> menu meta-cap => the granular cap it maps to
     */
    public const MENU_AREAS = [
        'giveflow_access_reports'   => 'giveflow_view_reports',
        'giveflow_access_campaigns' => 'giveflow_manage_campaigns',
        'giveflow_access_donations' => 'giveflow_view_donations',
        'giveflow_access_donors'    => 'giveflow_view_donors',
        'giveflow_access_forms'     => 'giveflow_manage_forms',
        'giveflow_access_settings'  => 'giveflow_manage_settings',
    ];

    /**
     * `user_has_cap` filter granting the virtual menu meta-caps (see MENU_AREAS).
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
            foreach ((array) apply_filters('giveflow.capabilities.admin_caps', []) as $cap) {
                if (is_string($cap) && $cap !== '') {
                    $allcaps[$cap] = true;
                }
            }
        }

        if ($super || ! empty($allcaps[self::MANAGE]) || $anyArea) {
            $allcaps['giveflow_access'] = true;
        }

        return $allcaps;
    }

    /**
     * Apply a role-to-caps mapping to all registered WP roles. A role that
     * receives at least one granular cap also gets the MANAGE umbrella so it
     * can see the GiveFlow menu; the administrator always keeps MANAGE. Runs on
     * activation and whenever the roles mapping is saved.
     *
     * @since 1.0.0
     */
    public static function applyMapping(array $mapping): void
    {
        $allCaps = self::all();
        foreach (wp_roles()->role_objects as $slug => $role) {
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
        $stored = get_option('giveflow_roles', []);
        $map    = is_array($stored['mapping'] ?? null) ? $stored['mapping'] : [];
        return $map;
    }
}
