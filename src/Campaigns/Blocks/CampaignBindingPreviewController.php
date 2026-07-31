<?php

declare(strict_types=1);

namespace Dono\Campaigns\Blocks;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The values a bound block should show while the page is being edited.
 *
 * The block editor resolves bindings on the client and cannot call a PHP
 * source, so without this a block bound to dono/campaign displays the source's
 * label, "Dono campaign", and the organiser composes a page they cannot read.
 * This hands the editor the same values the front end will render, computed by
 * the same code, so the preview cannot drift from the page.
 *
 * @version 1.0.0
 */
final class CampaignBindingPreviewController
{
    private const NS = 'dono/v1';

    public function __construct(
        private CampaignRepository $campaigns,
        private CampaignBindings $bindings,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/campaign-binding-preview/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'id'          => ['type' => 'integer'],
                'campaign_id' => ['type' => 'integer', 'required' => false],
            ],
        ]);
    }

    /**
     * Editing capability, not a campaign capability: this returns nothing a
     * visitor of the published page cannot already read, and it is needed by
     * anyone the site lets edit a page.
     */
    public function canAccess(): bool
    {
        return current_user_can('edit_posts');
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $campaign = $this->resolve($request);

        return new WP_REST_Response([
            'id'          => (int) $request['id'],
            'campaign_id' => $campaign ? (int) $campaign->id : 0,
            'campaign'    => $campaign ? $this->bindings->valuesFor($campaign) : null,
        ]);
    }

    /** An explicitly pinned campaign, else the one the edited page belongs to. */
    private function resolve(WP_REST_Request $request): ?Campaign
    {
        $explicit = (int) ($request['campaign_id'] ?? 0);
        if ($explicit > 0) {
            return $this->campaigns->findById($explicit);
        }

        $postId = (int) $request['id'];
        if ($postId <= 0) {
            return null;
        }
        $bound = (int) get_post_meta($postId, '_dono_campaign_id', true);

        return $bound > 0 ? $this->campaigns->findById($bound) : null;
    }
}
