<?php

declare(strict_types=1);

namespace FundKit\Rest\Admin;
use FundKit\Dashboard\AttentionDismissals;
use FundKit\Foundation\Auth\Capabilities;
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
 * @since 1.0.0
 */
final class UserPrefsController
{
    private const NAMESPACE  = 'fundkit/v1';
    private const META_KEY   = 'fundkit_widget_layout';
    private const VIEWS_META = 'fundkit_table_views';

    /**
     * A saved view is a convenience, not a document. The cap is per scope and
     * generous for what a view holds; it exists so a client bug cannot grow one
     * user's meta without bound.
     */
    private const MAX_VIEW_BYTES = 8192;

    /** Bounds on a saved page size, independent of what the client offers. */
    private const PER_PAGE_MIN = 1;
    private const PER_PAGE_MAX = 100;

    /**
     * The per-scope cap bounds one view; this bounds the row. There is one
     * scope per list screen, so a request inventing them past this is not a
     * client of ours and has no claim on the space.
     */
    private const MAX_SCOPES = 40;

    /** @since 1.0.0 */
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

        register_rest_route(self::NAMESPACE, '/admin/me/table-view', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'showView'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'scope' => ['type' => 'string', 'default' => 'default'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateView'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'scope' => ['type' => 'string', 'default' => 'default'],
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

    /** @since 1.0.0 */
    public function dismissAttention(WP_REST_Request $request): WP_REST_Response
    {
        (new AttentionDismissals())->dismiss(
            get_current_user_id(),
            (string) $request->get_param('key'),
            (string) $request->get_param('signature'),
        );

        return new WP_REST_Response(['dismissed' => true], 200);
    }

    /** @since 1.0.0 */
    public function restoreAttention(WP_REST_Request $request): WP_REST_Response
    {
        (new AttentionDismissals())->restore(
            get_current_user_id(),
            (string) $request->get_param('key'),
        );

        return new WP_REST_Response(['dismissed' => false], 200);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return is_user_logged_in() && Capabilities::canAccessAdmin();
    }

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
    public function showView(WP_REST_Request $request): WP_REST_Response
    {
        $all = $this->readViews();

        return new WP_REST_Response((object) ($all[$this->scopeKey($request)] ?? []), 200);
    }

    /** @since 1.0.0 */
    public function updateView(WP_REST_Request $request): WP_REST_Response
    {
        $all   = $this->readViews();
        $key   = $this->scopeKey($request);
        $clean = $this->sanitiseView((array) $request->get_json_params());

        if ($clean === []) {
            unset($all[$key]);
        } else {
            if (! isset($all[$key]) && count($all) >= self::MAX_SCOPES) {
                return new WP_REST_Response((object) [], 200);
            }
            $all[$key] = $clean;
        }

        update_user_meta(get_current_user_id(), self::VIEWS_META, wp_json_encode($all));

        return new WP_REST_Response((object) $clean, 200);
    }

    /**
     * Persist view preferences without search or page. Keep hidden columns in order so they
     * regain their position and new columns can be detected.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    private function sanitiseView(array $body): array
    {
        $view = [];

        foreach (['fields', 'order'] as $list) {
            if (isset($body[$list]) && is_array($body[$list])) {
                $view[$list] = array_values(array_filter($body[$list], 'is_string'));
            }
        }

        if (isset($body['type']) && is_string($body['type'])) {
            $view['type'] = sanitize_key($body['type']);
        }

        if (isset($body['perPage'])) {
            $view['perPage'] = max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, (int) $body['perPage']));
        }

        if (isset($body['sort']) && is_array($body['sort'])) {
            $rawField     = $body['sort']['field'] ?? '';
            $rawDirection = $body['sort']['direction'] ?? '';
            $field     = is_scalar($rawField) ? (string) $rawField : '';
            $direction = is_scalar($rawDirection) ? strtolower((string) $rawDirection) : '';
            if ($field !== '') {
                $view['sort'] = [
                    'field'     => sanitize_key($field),
                    'direction' => $direction === 'asc' ? 'asc' : 'desc',
                ];
            }
        }

        if (isset($body['filters']) && is_array($body['filters'])) {
            $view['filters'] = $this->sanitiseFilters($body['filters']);
        }

        // Cheaper to drop an oversized view than to store one: the client
        // rebuilds it from the screen's defaults on the next load.
        return strlen((string) wp_json_encode($view)) > self::MAX_VIEW_BYTES ? [] : $view;
    }

    /**
     * A filter value is whatever the column filters on, so it is left as its own
     * scalar rather than cast; a list value keeps only its scalar members.
     *
     * @param array<int, mixed> $filters
     * @return array<int, array<string, mixed>>
     *
     * @since 1.0.0
     */
    private function sanitiseFilters(array $filters): array
    {
        $clean = [];

        foreach ($filters as $filter) {
            if (! is_array($filter) || ! isset($filter['field'])) {
                continue;
            }

            $value = $filter['value'] ?? null;
            if (is_array($value)) {
                $value = array_values(array_filter($value, 'is_scalar'));
            } elseif (! is_scalar($value) && $value !== null) {
                continue;
            }

            $operator = $filter['operator'] ?? 'is';
            if (! is_scalar($filter['field']) || ! is_scalar($operator)) {
                continue;
            }

            $clean[] = [
                'field'    => sanitize_key((string) $filter['field']),
                'operator' => sanitize_key((string) $operator),
                'value'    => $value,
            ];
        }

        return $clean;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @since 1.0.0
     */
    private function readViews(): array
    {
        $raw = get_user_meta(get_current_user_id(), self::VIEWS_META, true);
        $all = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return is_array($all) ? $all : [];
    }

    /**
     * @return array<string, array{order:array<int,string>, hidden:array<int,string>}>
     *
     * @since 1.0.0
     */
    private function readAll(): array
    {
        $raw = get_user_meta(get_current_user_id(), self::META_KEY, true);
        $all = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        return is_array($all) ? $all : [];
    }

    /** @since 1.0.0 */
    private function scopeKey(WP_REST_Request $request): string
    {
        $scope = (string) ($request->get_param('scope') ?? '');
        $scope = preg_replace('/[^a-zA-Z0-9_\-]/', '', $scope);
        return $scope !== '' ? $scope : 'default';
    }
}
