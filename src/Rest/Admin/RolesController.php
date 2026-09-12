<?php

declare(strict_types=1);

namespace Gratora\Rest\Admin;

use Gratora\Foundation\Auth\Capabilities;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The roles available for capability mapping, and the capabilities to map.
 *
 * The capability list belongs here rather than in the panel's own source:
 * add-ons register their caps through the `gratora.capabilities` filter, which
 * `Capabilities::maps()` applies and `applyMapping()` honors. A hardcoded copy
 * in the screen would enforce an add-on's capability on every route while
 * leaving it ungrantable (gratora-p2p's `gratora_manage_fundraisers`).
 *
 * @since 1.0.0
 */
final class RolesController
{
    private const NAMESPACE = 'gratora/v1';

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/roles', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return current_user_can('manage_options');
    }

    /** @since 1.0.0 */
    public function index(): WP_REST_Response
    {
        return new WP_REST_Response([
            'roles'        => $this->roles(),
            'capabilities' => $this->capabilities(),
        ], 200);
    }

    /**
     * @return list<array{slug:string,name:string}>
     *
     * @since 1.0.0
     */
    private function roles(): array
    {
        // Core WP roles only; third-party roles aren't capability-mapping targets.
        $core = Capabilities::MANAGED_ROLES;

        $out = [];
        foreach ($core as $slug) {
            $role = wp_roles()->roles[$slug] ?? null;
            if (! $role) continue;
            $out[] = [
                'slug' => $slug,
                'name' => translate_user_role((string) ($role['name'] ?? $slug)),
            ];
        }
        return $out;
    }

    /**
     * A group key is an identifier add-ons merge on, so it cannot be
     * translated where it is declared. Core's four are translated here, at the
     * display boundary; an add-on's own heading is the add-on's to translate.
     *
     * @since 1.0.0
     */
    private static function groupLabel(string $key): string
    {
        $labels = [
            'Donors'    => __('Donors', 'gratora-donation-platform'),
            'Donations' => __('Donations', 'gratora-donation-platform'),
            'Reports'   => __('Reports', 'gratora-donation-platform'),
            'Setup'     => __('Setup', 'gratora-donation-platform'),
        ];

        return $labels[$key] ?? $key;
    }

    /**
     * Grouped for display, in the order Capabilities declares them, with any
     * capability an add-on registered outside a known group gathered at the end
     * rather than dropped.
     *
     * @return list<array{label:string,caps:list<array{cap:string,label:string}>}>
     *
     * @since 1.0.0
     */
    private function capabilities(): array
    {
        $labels = Capabilities::labels();
        $seen   = [];
        $out    = [];

        foreach (Capabilities::groups() as $label => $caps) {
            $rows = [];
            foreach ($caps as $cap) {
                $seen[$cap] = true;
                $rows[] = ['cap' => $cap, 'label' => (string) ($labels[$cap] ?? $cap)];
            }
            if ($rows !== []) {
                $out[] = ['label' => self::groupLabel((string) $label), 'caps' => $rows];
            }
        }

        $ungrouped = [];
        foreach (Capabilities::all() as $cap) {
            if (isset($seen[$cap])) continue;
            $ungrouped[] = ['cap' => $cap, 'label' => (string) ($labels[$cap] ?? $cap)];
        }
        if ($ungrouped !== []) {
            $out[] = ['label' => __('Other', 'gratora-donation-platform'), 'caps' => $ungrouped];
        }

        return $out;
    }
}
