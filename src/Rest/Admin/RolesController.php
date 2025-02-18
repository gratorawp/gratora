<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use WP_REST_Response;
use WP_REST_Server;

/**
 * Returns the core WP roles available for capability mapping.
 *
 * @version 1.0.0
 */
final class RolesController
{
    private const NAMESPACE = 'dono/v1';

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/roles', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    public function canAccess(): bool
    {
        return current_user_can('manage_options');
    }

    public function index(): WP_REST_Response
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
        return new WP_REST_Response($out, 200);
    }
}
