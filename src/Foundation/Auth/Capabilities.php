<?php

declare(strict_types=1);

namespace FundKit\Foundation\Auth;

/**
 * All plugin capability constants and role-mapping helpers.
 *
 * @since 1.0.0
 */
final class Capabilities
{
    /** Umbrella cap: "can reach the FundKit admin area at all" (menu + base gate). */
    public const MANAGE = 'manage_fundkit';

    public const ALL = [
        'fundkit_view_donors',
        'fundkit_edit_donors',
        'fundkit_export_donors',
        'fundkit_redact_donors',
        'fundkit_view_donations',
        'fundkit_edit_donations',
        'fundkit_refund_donations',
        'fundkit_resend_receipt',
        'fundkit_view_reports',
        'fundkit_manage_campaigns',
        'fundkit_manage_forms',
        'fundkit_manage_settings',
    ];

    public const GROUPS = [
        'Donors'    => ['fundkit_view_donors', 'fundkit_edit_donors', 'fundkit_export_donors', 'fundkit_redact_donors'],
        'Donations' => ['fundkit_view_donations', 'fundkit_edit_donations', 'fundkit_refund_donations', 'fundkit_resend_receipt'],
        'Reports'   => ['fundkit_view_reports'],
        'Setup'     => ['fundkit_manage_campaigns', 'fundkit_manage_forms', 'fundkit_manage_settings'],
    ];

    public const LABELS = [
        'fundkit_view_donors'      => 'View donors',
        'fundkit_edit_donors'      => 'Edit donor records',
        'fundkit_export_donors'    => 'Export donor list (CSV)',
        'fundkit_redact_donors'    => 'Redact donors (GDPR)',
        'fundkit_view_donations'   => 'View donations',
        'fundkit_edit_donations'   => 'Edit donations (notes)',
        'fundkit_refund_donations' => 'Change what is charged (refund, mark paid, record by hand, change a recurring plan)',
        'fundkit_resend_receipt'   => 'Resend receipts',
        'fundkit_view_reports'     => 'View dashboards & reports',
        'fundkit_manage_campaigns' => 'Manage campaigns',
        'fundkit_manage_forms'     => 'Manage donation forms',
        'fundkit_manage_settings'  => 'Manage settings',
    ];

    /**
     * The capability maps with add-on registrations applied.
     *
     * @return array{all:array<int,string>,groups:array<string,array<int,string>>,labels:array<string,string>}
     * @since 1.0.0
     */
    private static function maps(): array
    {
        $maps = apply_filters('fundkit.capabilities', [
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
     * True for anyone who may reach the FundKit admin area at all (menu/base gate).
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
     * fundkit_access_* grants on manage_options or the area's granular cap. REST still
     * enforces the granular caps, so menu visibility never widens actual access.
     *
     * @var array<string,string> menu meta-cap => the granular cap it maps to
     */
    /**
     * The roles the Roles screen can edit, and so the only roles whose absence
     * from the mapping means revoke. A role granted FundKit capabilities by a
     * role editor, a theme or an add-on is invisible to that screen, and had
     * them stripped on the next save of it.
     *
     * @var list<string>
     */
    public const MANAGED_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];

    public const MENU_AREAS = [
        'fundkit_access_reports'   => 'fundkit_view_reports',
        'fundkit_access_campaigns' => 'fundkit_manage_campaigns',
        'fundkit_access_donations' => 'fundkit_view_donations',
        'fundkit_access_donors'    => 'fundkit_view_donors',
        'fundkit_access_forms'     => 'fundkit_manage_forms',
        'fundkit_access_settings'  => 'fundkit_manage_settings',
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
            foreach ((array) apply_filters('fundkit.capabilities.admin_caps', []) as $cap) {
                if (is_string($cap) && $cap !== '') {
                    $allcaps[$cap] = true;
                }
            }
        }

        if ($super || ! empty($allcaps[self::MANAGE]) || $anyArea) {
            $allcaps['fundkit_access'] = true;
        }

        return $allcaps;
    }

    /**
     * Apply a role-to-caps mapping to the roles the mapping names and the roles
     * the Roles screen edits. A role that receives at least one granular cap
     * also gets the MANAGE umbrella so it can see the FundKit menu; the
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
        $stored = get_option('fundkit_roles', []);
        $map    = is_array($stored['mapping'] ?? null) ? $stored['mapping'] : [];
        return $map;
    }
}
