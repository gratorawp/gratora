<?php

declare(strict_types=1);

namespace Gratora\Rest\Admin;

use Gratora\Campaigns\StarterCampaign;
use Gratora\Campaigns\StarterCampaignRefused;
use Gratora\Dashboard\FirstRun;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Onboarding\Onboarding;
use RuntimeException;
use WP_Error;
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
    public function __construct(
        private StarterCampaign $starter,
        private FirstRun $firstRun,
    ) {
    }

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

        register_rest_route(self::NAMESPACE, '/admin/onboarding/starter-campaign', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'starterCampaign'],
            'permission_callback' => [$this, 'canCreateCampaigns'],
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

        return new WP_REST_Response(['ok' => true, 'first_run' => $this->firstRun->facts()], 200);
    }


    /** @unreleased */
    public function canCreateCampaigns(): bool
    {
        return Capabilities::userCan('gratora_manage_campaigns');
    }

    /**
     * The site's first donation page: made now, or the one made earlier.
     *
     * @unreleased
     */
    public function starterCampaign(): WP_REST_Response|WP_Error
    {
        try {
            $campaign = $this->starter->ensure();
        } catch (StarterCampaignRefused $e) {
            return new WP_Error('gratora_starter_campaign_refused', $e->getMessage(), ['status' => 409]);
        } catch (RuntimeException $e) {
            return new WP_Error('gratora_campaign_create_failed', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response([
            'campaign_id' => (int) $campaign->id,
            'page_url'    => (string) get_permalink((int) $campaign->page_id),
        ], 200);
    }

    /** @since 1.0.0 */
    public function dismiss(): WP_REST_Response
    {
        update_option(Onboarding::OPTION, 'dismissed', false);
        return new WP_REST_Response(['ok' => true], 200);
    }
}
