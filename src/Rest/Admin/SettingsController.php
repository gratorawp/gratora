<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Currency\BaseCurrencyLock;
use Dono\Currency\BaseCurrencyLocked;
use Dono\Donors\DonorRetention;
use Dono\Settings\SettingsService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Dono\Settings\SecretRedactor;

/**
 * Admin settings read/write by group key.
 *
 * @since 1.0.0
 */
final class SettingsController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private SettingsService $settings,
        private DonorRetention $retention,
    ) {
    }

    /** @since 1.0.0 */
    public function retentionPreview(WP_REST_Request $request): WP_REST_Response
    {
        $years = $request['years'] === null ? null : (int) $request['years'];

        return new WP_REST_Response($this->retention->preview((int) $request['days'], $years), 200);
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        // Read-only, and deliberately not part of the privacy group: it is a
        // count of what the retention sweep would take, not a setting. Omitting
        // years asks about the window in force; sending one asks about a window
        // the panel is still choosing, which is when the count can still stop
        // someone.
        register_rest_route(self::NAMESPACE, '/admin/settings/retention-preview', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'retentionPreview'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'days'  => ['type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 365],
                'years' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
        ]);

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

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    /** @since 1.0.0 */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('dono_unknown_group', __('Unknown settings group.', 'dono-fundraising-platform'), ['status' => 404]);
        }
        // Never hand a stored secret back out. The gateways group holds the
        // Stripe webhook signing secret, which is the only authentication on
        // the webhook route: reading it is enough to forge a paid donation.
        $data = SecretRedactor::redact($this->settings->get($group));

        // Read-only, and not part of the stored shape: accept() drops it on the
        // way back in. The screen needs it to disable the picker rather than
        // let someone choose a currency the save will refuse.
        if ($group === 'currency-locale') {
            $data['base_currency_locked'] = BaseCurrencyLock::isLocked();
        }

        return new WP_REST_Response($data, 200);
    }

    /** @since 1.0.0 */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('dono_unknown_group', __('Unknown settings group.', 'dono-fundraising-platform'), ['status' => 404]);
        }
        // Assigning Dono capabilities to roles grants privileges, so it needs
        // full admin - not the delegatable dono_manage_settings, which a scoped
        // role could otherwise use to grant itself refund/redact/export caps.
        if ($group === 'roles' && ! current_user_can('manage_options')) {
            return new WP_Error('dono_forbidden', __('Managing roles requires full administrator access.', 'dono-fundraising-platform'), ['status' => 403]);
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
        // The read path masks secrets, so a client that round-trips the group
        // sends the mask back. Put the stored value behind it, otherwise saving
        // any unrelated field would wipe the signing secret.
        $body  = SecretRedactor::restore($body, $this->settings->get($group));

        // The invariant lives in SettingsService so every writer inherits it;
        // the controller's job is only to give the refusal an HTTP shape.
        try {
            $saved = $this->settings->update($group, $body);
        } catch (BaseCurrencyLocked $e) {
            return new WP_Error('dono_base_currency_locked', $e->getMessage(), ['status' => 409]);
        }

        return new WP_REST_Response(SecretRedactor::redact($saved), 200);
    }

    /**
     * Recursively sanitize settings values. Email/receipt templates are sent as
     * plain text, so settings hold no HTML; sanitize_textarea_field strips tags
     * + control chars while preserving line breaks and leaving ampersands and a
     * stray "<" intact (wp_kses_post would turn "&" into "&amp;" and eat "<").
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     *
     * @since 1.0.0
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
