<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Foundation\License\LicenseService;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin endpoint for the current license state snapshot.
 *
 * @version 1.0.0
 */
final class LicenseController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(private LicenseService $license)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/license', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    public function canAccess(): bool
    {
        return Capabilities::canAccessAdmin();
    }

    public function show(): WP_REST_Response
    {
        return new WP_REST_Response($this->license->snapshot(), 200);
    }
}
