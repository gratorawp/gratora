<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Settings\ReadinessService;
use WP_REST_Response;
use WP_REST_Server;

/**
 * One round trip for the Setup screen's whole verdict, rather than a fan-out
 * over every gateway, page and queue endpoint.
 *
 * @since 1.0.0
 */
final class ReadinessController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(private ReadinessService $readiness)
    {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/readiness', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings') || current_user_can('manage_options');
    }

    /** @since 1.0.0 */
    public function show(): WP_REST_Response
    {
        $checks = $this->readiness->check();

        return new WP_REST_Response([
            'checks'   => $checks,
            'live'     => $this->readiness->isLive($checks),
            'blockers' => count(array_filter(
                $checks,
                static fn (array $c): bool => ! empty($c['blocker']) && $c['status'] === ReadinessService::FAIL
            )),
            'warnings' => count(array_filter(
                $checks,
                static fn (array $c): bool => $c['status'] === ReadinessService::WARN
            )),
        ], 200);
    }
}
