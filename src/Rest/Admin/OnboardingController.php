<?php

declare(strict_types=1);

namespace Gratora\Rest\Admin;

use Gratora\Onboarding\Onboarding;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Persist per-step settings through /admin/settings/{group}.
 *
 * @since 1.0.0
 */
final class OnboardingController
{
    private const NAMESPACE = 'gratora/v1';

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/onboarding/finalize', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'finalize'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/onboarding/dismiss', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'dismiss'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

    }


    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Close out the wizard. Settles the organization only: it creates no
     * campaign and leaves test mode as it found it, since the wizard can be
     * opened for the first time on a site that has been live for months.
     *
     * @since 1.0.0
     */
    public function finalize(): WP_REST_Response
    {
        update_option(Onboarding::OPTION, 'completed', false);

        return new WP_REST_Response(['ok' => true], 200);
    }


    /** @since 1.0.0 */
    public function dismiss(): WP_REST_Response
    {
        update_option(Onboarding::OPTION, 'dismissed', false);
        return new WP_REST_Response(['ok' => true], 200);
    }
}
