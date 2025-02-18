<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Settings\SettingsService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin settings read/write by group key.
 *
 * @version 1.0.0
 */
final class SettingsController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(private SettingsService $settings)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/settings/(?P<group>[a-z0-9_-]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);
    }

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('dono_unknown_group', __('Unknown settings group.', 'dono'), ['status' => 404]);
        }
        return new WP_REST_Response($this->settings->get($group), 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('dono_unknown_group', __('Unknown settings group.', 'dono'), ['status' => 404]);
        }
        $body = (array) $request->get_json_params();
        // Whitelist to known top-level keys for this group so arbitrary keys
        // can't be planted in the option by a curious caller. SettingsService
        // deep-merges from here, so nested validation is each panel's job.
        $allowed = array_keys($this->settings->get($group));
        if ($allowed !== []) {
            $body = array_intersect_key($body, array_flip($allowed));
        }
        $body = $this->sanitize($body);
        return new WP_REST_Response($this->settings->update($group, $body), 200);
    }

    /**
     * Recursively sanitize settings values. Email/receipt templates are sent as
     * plain text, so settings hold no HTML; sanitize_textarea_field strips tags
     * + control chars while preserving line breaks and leaving ampersands and a
     * stray "<" intact (wp_kses_post would turn "&" into "&amp;" and eat "<").
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = sanitize_textarea_field($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}
