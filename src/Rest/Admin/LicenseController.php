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
 * @version 2.0.0
 */
final class LicenseController
{
    private const NAMESPACE  = 'dono/v1';
    private const OPTION_KEY = 'dono_pro_license_key';

    public function __construct(private LicenseService $license)
    {
    }

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

    public function canAccess(): bool
    {
        return Capabilities::canAccessAdmin();
    }

    public function show(): WP_REST_Response
    {
        return new WP_REST_Response($this->payload(), 200);
    }

    public function activate(WP_REST_Request $request): WP_REST_Response
    {
        $key = sanitize_text_field((string) $request->get_param('key'));
        update_option(self::OPTION_KEY, $key, false);

        return new WP_REST_Response($this->payload(), 200);
    }

    public function deactivate(): WP_REST_Response
    {
        delete_option(self::OPTION_KEY);

        return new WP_REST_Response($this->payload(), 200);
    }

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
     */
    private function payload(): array
    {
        $key    = (string) get_option(self::OPTION_KEY, '');
        $hasKey = $key !== '';

        return array_merge($this->license->snapshot(), [
            'has_key'    => $hasKey,
            'key_masked' => $hasKey ? $this->mask($key) : '',
            'addons'     => $this->license->addons(),
        ]);
    }

    /** First 8 characters of the key, then an ellipsis. */
    private function mask(string $key): string
    {
        return substr($key, 0, 8) . '...';
    }
}
