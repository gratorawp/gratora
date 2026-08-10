<?php

declare(strict_types=1);

namespace Dono\Foundation\Auth;

/**
 * All plugin capability constants and role-mapping helpers.
 *
 * @since 1.0.0
 */
final class Capabilities
{
    /** Umbrella cap: "can reach the Dono admin area at all" (menu + base gate). */
    public const MANAGE = 'manage_dono';

    public const ALL = [
        'dono_view_donors',
        'dono_edit_donors',
        'dono_export_donors',
        'dono_redact_donors',
        'dono_view_donations',
        'dono_edit_donations',
        'dono_refund_donations',
        'dono_resend_receipt',
        'dono_view_reports',
        'dono_manage_campaigns',
        'dono_manage_forms',
        'dono_manage_settings',
    ];

    public const GROUPS = [
        'Donors'    => ['dono_view_donors', 'dono_edit_donors', 'dono_export_donors', 'dono_redact_donors'],
        'Donations' => ['dono_view_donations', 'dono_edit_donations', 'dono_refund_donations', 'dono_resend_receipt'],
        'Reports'   => ['dono_view_reports'],
        'Setup'     => ['dono_manage_campaigns', 'dono_manage_forms', 'dono_manage_settings'],
    ];

    public const LABELS = [
        'dono_view_donors'      => 'View donors',
        'dono_edit_donors'      => 'Edit donor records',
        'dono_export_donors'    => 'Export donor list (CSV)',
        'dono_redact_donors'    => 'Redact donors (GDPR)',
        'dono_view_donations'   => 'View donations',
        'dono_edit_donations'   => 'Edit donations (notes)',
        'dono_refund_donations' => 'Change what is charged (refund, mark paid, record by hand, change a recurring plan)',
        'dono_resend_receipt'   => 'Resend receipts',
        'dono_view_reports'     => 'View dashboards & reports',
        'dono_manage_campaigns' => 'Manage campaigns',
        'dono_manage_forms'     => 'Manage donation forms',
        'dono_manage_settings'  => 'Manage settings',
    ];

    /**
     * The capability maps with add-on registrations applied.
     *
     * @return array{all:array<int,string>,groups:array<string,array<int,string>>,labels:array<string,string>}
     * @since 1.0.0
     */
    private static function maps(): array
    {
        $maps = apply_filters('dono.capabilities', [
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
     * True for anyone who may reach the Dono admin area at all (menu/base gate).
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
     * dono_access_* grants on manage_options or the area's granular cap. REST still
     * enforces the granular caps, so menu visibility never widens actual access.
     *
     * @var array<string,string> menu meta-cap => the granular cap it maps to
     */
    public const MENU_AREAS = [
        'dono_access_reports'   => 'dono_view_reports',
        'dono_access_campaigns' => 'dono_manage_campaigns',
        'dono_access_donations' => 'dono_view_donations',
        'dono_access_donors'    => 'dono_view_donors',
        'dono_access_forms'     => 'dono_manage_forms',
        'dono_access_settings'  => 'dono_manage_settings',
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
            foreach ((array) apply_filters('dono.capabilities.admin_caps', []) as $cap) {
                if (is_string($cap) && $cap !== '') {
                    $allcaps[$cap] = true;
                }
            }
        }

        if ($super || ! empty($allcaps[self::MANAGE]) || $anyArea) {
            $allcaps['dono_access'] = true;
        }

        return $allcaps;
    }

    /**
     * Apply a role-to-caps mapping to all registered WP roles. A role that
     * receives at least one granular cap also gets the MANAGE umbrella so it
     * can see the Dono menu; the administrator always keeps MANAGE. Runs on
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
        $stored = get_option('dono_roles', []);
        $map    = is_array($stored['mapping'] ?? null) ? $stored['mapping'] : [];
        return $map;
    }
}
