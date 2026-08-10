<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Dashboard\AttentionDismissals;
use Dono\Foundation\Auth\Capabilities;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Per-user admin preferences: widget layout, and the attention items this user
 * has waved off.
 *
 * Layout is user meta as JSON keyed by scope: { [scope]: { order: string[],
 * hidden: string[] } }. Unknown scope keys are ignored client-side, so new
 * widgets need no migration.
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

        register_rest_route(self::NAMESPACE, '/admin/me/attention/dismiss', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'dismissAttention'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'key'       => ['type' => 'string', 'required' => true],
                // The state the user was looking at. Held so the item returns
                // when that state moves on rather than staying hidden for good.
                'signature' => ['type' => 'string', 'default' => 'x'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/me/attention/restore', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'restoreAttention'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'key' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function dismissAttention(WP_REST_Request $request): WP_REST_Response
    {
        (new AttentionDismissals())->dismiss(
            get_current_user_id(),
            (string) $request->get_param('key'),
            (string) $request->get_param('signature'),
        );

        return new WP_REST_Response(['dismissed' => true], 200);
    }

    public function restoreAttention(WP_REST_Request $request): WP_REST_Response
    {
        (new AttentionDismissals())->restore(
            get_current_user_id(),
            (string) $request->get_param('key'),
        );

        return new WP_REST_Response(['dismissed' => false], 200);
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
