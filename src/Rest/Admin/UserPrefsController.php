<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Per-user widget layout stored in user meta as JSON keyed by scope:
 * { [scope]: { order: string[], hidden: string[] } }. Unknown scope keys
 * are ignored client-side, so new widgets need no migration.
 *
 * @version 1.0.0
 */
final class UserPrefsController
{
    private const NAMESPACE = 'dono/v1';
    private const META_KEY  = 'dono_widget_layout';

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/me/layout', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'scope' => ['type' => 'string', 'default' => 'default'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'scope'  => ['type' => 'string', 'default' => 'default'],
                    'order'  => ['type' => 'array', 'items' => ['type' => 'string']],
                    'hidden' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ]);
    }

    public function canAccess(): bool
    {
        return is_user_logged_in() && Capabilities::canAccessAdmin();
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $all = $this->readAll();
        $key = $this->scopeKey($request);
        $layout = $all[$key] ?? ['order' => [], 'hidden' => []];

        return new WP_REST_Response([
            'order'  => array_values(array_filter((array) ($layout['order']  ?? []), 'is_string')),
            'hidden' => array_values(array_filter((array) ($layout['hidden'] ?? []), 'is_string')),
        ], 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $all  = $this->readAll();
        $key  = $this->scopeKey($request);
        $body = (array) $request->get_json_params();

        $all[$key] = [
            'order'  => array_values(array_filter((array) ($body['order']  ?? []), 'is_string')),
            'hidden' => array_values(array_filter((array) ($body['hidden'] ?? []), 'is_string')),
        ];

        update_user_meta(get_current_user_id(), self::META_KEY, wp_json_encode($all));

        return new WP_REST_Response($all[$key], 200);
    }

    /** @return array<string, array{order:array<int,string>, hidden:array<int,string>}> */
    private function readAll(): array
    {
        $raw = get_user_meta(get_current_user_id(), self::META_KEY, true);
        $all = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        return is_array($all) ? $all : [];
    }

    private function scopeKey(WP_REST_Request $request): string
    {
        $scope = (string) ($request->get_param('scope') ?? '');
        $scope = preg_replace('/[^a-zA-Z0-9_\-]/', '', $scope);
        return $scope !== '' ? $scope : 'default';
    }
}
