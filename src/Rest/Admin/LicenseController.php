<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\License\LicenseService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin endpoints for the central single-key license: read the current
 * snapshot, activate or deactivate the site key, and force a re-check. The key
 * lives in the dono_pro_license_key option, the same one the dono-licensing
 * client reads, so there is a single source of truth.
 *
 * @since 1.0.0
 */
final class LicenseController
{
    private const NAMESPACE  = 'dono/v1';
    private const OPTION_KEY = 'dono_pro_license_key';

    /** @since 1.0.0 */
    public function __construct(private LicenseService $license)
    {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/license', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'activate'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'key' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deactivate'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/license/recheck', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'recheck'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    /**
     * The license enables or disables every paid add-on site-wide, so it is a
     * settings-level write, not a "can this person see the Dono admin" one.
     * canAccessAdmin() is true for anyone holding any single dono_* cap, which
     * would let a read-only donor viewer plant an arbitrary key or delete the
     * real one and knock out every Pro add-on.
     *
     * @since 1.0.0
     */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings') || current_user_can('manage_options');
    }

    /** @since 1.0.0 */
    public function show(): WP_REST_Response
    {
        return new WP_REST_Response($this->payload(), 200);
    }

    /**
     * Storing the key is what activates it: the licensing client listens on
     * this option and calls the server synchronously, so the payload built
     * afterwards already carries the verdict. The client is only present
     * alongside a paid add-on, so with none installed this is just a stored
     * key and every product reports unknown.
     *
     * @since 1.0.0
     */
    public function activate(WP_REST_Request $request): WP_REST_Response
    {
        $key = sanitize_text_field((string) $request->get_param('key'));
        if ($key === '') {
            return new WP_REST_Response([
                'code'    => 'dono_license_empty',
                'message' => __('Enter your license key.', 'dono'),
            ], 400);
        }

        update_option(self::OPTION_KEY, $key, false);

        $payload = $this->payload();

        // A key the server rejected is worse than no key, because the screen
        // would otherwise show it as stored and look activated.
        if ($payload['checked'] && ! $payload['any_entitled']) {
            return new WP_REST_Response($payload + [
                'code'    => 'dono_license_rejected',
                'message' => __('That key was not accepted for any installed add-on.', 'dono'),
            ], 200);
        }

        return new WP_REST_Response($payload, 200);
    }

    /** @since 1.0.0 */
    public function deactivate(): WP_REST_Response
    {
        delete_option(self::OPTION_KEY);

        return new WP_REST_Response($this->payload(), 200);
    }

    /** @since 1.0.0 */
    public function recheck(): WP_REST_Response
    {
        // Lets the dono-licensing client (when present) revalidate against the
        // server; the status filter then reflects the fresh result.
        do_action('dono_licensing_recheck');

        return new WP_REST_Response($this->payload(), 200);
    }

    /**
     * The entitlement snapshot enriched for the admin screen: whether a key is
     * stored, a masked form of it for display, and the installed Pro add-ons as
     * id + name pairs.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function payload(): array
    {
        $key    = (string) get_option(self::OPTION_KEY, '');
        $hasKey = $key !== '';

        // Per add-on entitlement comes from the licensing client through the
        // seam it already publishes. With no client the filter passes the
        // default straight back, which is how we tell "nobody checked" from
        // "checked and refused".
        $addons  = $this->license->entitlements();
        $checked = array_reduce($addons, static fn (bool $c, array $a): bool => $c || $a['status'] !== 'unknown', false);

        return array_merge($this->license->snapshot(), [
            'has_key'      => $hasKey,
            'key_masked'   => $hasKey ? $this->mask($key) : '',
            'addons'       => $addons,
            'checked'      => $checked,
            'any_entitled' => array_reduce($addons, static fn (bool $c, array $a): bool => $c || $a['entitled'], false),
        ]);
    }

    /** @since 1.0.0 */
    private function mask(string $key): string
    {
        return substr($key, 0, 8) . '...';
    }
}
