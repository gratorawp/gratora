<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Onboarding\Onboarding;
use Dono\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * First-run onboarding lifecycle transitions (finalize, dismiss). Per-step settings
 * are persisted by /admin/settings/{group}, not here.
 *
 * @since 1.0.0
 */
final class OnboardingController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private SettingsService $settings,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/onboarding/finalize', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'finalize'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'campaign_title' => ['type' => 'string'],
                'user_type'      => ['type' => 'string'],
                'goal_mode'      => ['type' => 'string'],
                'goal_amount'    => ['type' => 'integer'],
            ],
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
     * Close out the wizard. Settles the organization only, and deliberately
     * creates no campaign; the last screen links to the campaigns page with
     * its create drawer open instead.
     *
     * @since 1.0.0
     */
    public function finalize(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) ($request->get_json_params() ?? []);

        update_option(Onboarding::OPTION, 'completed', false);

        // "Just exploring" starts the org in test mode: nothing takes real
        // money and a test/sandbox gateway is available until they switch
        // test mode off in Settings, Payment gateways.
        if (trim((string) ($body['user_type'] ?? '')) === 'exploring') {
            $this->settings->update('gateways', ['test_mode' => true]);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }


    /** @since 1.0.0 */
    public function dismiss(): WP_REST_Response
    {
        update_option(Onboarding::OPTION, 'dismissed', false);
        return new WP_REST_Response(['ok' => true], 200);
    }
}
