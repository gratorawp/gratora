<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The roles available for capability mapping, and the capabilities to map.
 *
 * The capability list belongs here rather than in the panel's own source:
 * add-ons register their caps through the `dono.capabilities` filter, which
 * `Capabilities::maps()` applies and `applyMapping()` honors. A hardcoded copy
 * in the screen would enforce an add-on's capability on every route while
 * leaving it ungrantable (dono-p2p's `dono_manage_fundraisers`).
 *
 * @since 1.0.0
 */
final class RolesController
{
    private const NAMESPACE = 'dono/v1';

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
        $core = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];

        $out = [];
        foreach ($core as $slug) {
            $role = wp_roles()->roles[$slug] ?? null;
            if (! $role) continue;
            $out[] = [
                'slug' => $slug,
                'name' => (string) ($role['name'] ?? $slug),
            ];
        }
        return $out;
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
                $out[] = ['label' => (string) $label, 'caps' => $rows];
            }
        }

        $ungrouped = [];
        foreach (Capabilities::all() as $cap) {
            if (isset($seen[$cap])) continue;
            $ungrouped[] = ['cap' => $cap, 'label' => (string) ($labels[$cap] ?? $cap)];
        }
        if ($ungrouped !== []) {
            $out[] = ['label' => __('Other', 'dono-fundraising-platform'), 'caps' => $ungrouped];
        }

        return $out;
    }
}
