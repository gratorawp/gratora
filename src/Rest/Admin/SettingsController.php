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
        $data = $this->settings->get($group);
        if ($group === 'gateways') {
            $data = $this->maskGatewaySecrets($data);
        }
        return new WP_REST_Response($data, 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('dono_unknown_group', __('Unknown settings group.', 'dono'), ['status' => 404]);
        }
        // Assigning Dono capabilities to roles grants privileges, so it needs
        // full admin - not the delegatable dono_manage_settings, which a scoped
        // role could otherwise use to grant itself refund/redact/export caps.
        if ($group === 'roles' && ! current_user_can('manage_options')) {
            return new WP_Error('dono_forbidden', __('Managing roles requires full administrator access.', 'dono'), ['status' => 403]);
        }
        $body = (array) $request->get_json_params();
        // Whitelist to known top-level keys for this group so arbitrary keys
        // can't be planted in the option by a curious caller. SettingsService
        // deep-merges from here, so nested validation is each panel's job.
        $allowed = array_keys($this->settings->get($group));
        if ($allowed !== []) {
            $body = array_intersect_key($body, array_flip($allowed));
        }
        if ($group === 'gateways') {
            // A webhook secret that comes back as the mask means "unchanged":
            // keep the stored value rather than overwriting it with the mask.
            $stored = $this->settings->get($group);
            foreach (self::GATEWAY_SECRET_KEYS as $k) {
                if (($body['stripe'][$k] ?? null) === self::SECRET_MASK) {
                    $body['stripe'][$k] = (string) ($stored['stripe'][$k] ?? '');
                }
            }
        }
        $body  = $this->sanitize($body);
        $saved = $this->settings->update($group, $body);
        if ($group === 'gateways') {
            $saved = $this->maskGatewaySecrets($saved);
        }
        return new WP_REST_Response($saved, 200);
    }

    private const SECRET_MASK = '••••••••';
    private const GATEWAY_SECRET_KEYS = ['webhook_secret_test', 'webhook_secret_live'];

    /**
     * Replace stored webhook secrets with a mask for the REST read: the UI
     * treats them as write-only. The mask is truthy so the "configured" status
     * still reads, and a resubmitted mask is preserved on update().
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function maskGatewaySecrets(array $data): array
    {
        foreach (self::GATEWAY_SECRET_KEYS as $k) {
            if (! empty($data['stripe'][$k])) {
                $data['stripe'][$k] = self::SECRET_MASK;
            }
        }
        return $data;
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
