<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Onboarding\Onboarding;
use Dono\Settings\SettingsService;
use InvalidArgumentException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * First-run onboarding lifecycle transitions (finalize, dismiss). Per-step settings
 * are persisted by /admin/settings/{group}, not here.
 */
final class OnboardingController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private CampaignService $campaigns,
        private SettingsService $settings,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/onboarding/finalize', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'finalize'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'campaign_title' => ['type' => 'string'],
                'currency'       => ['type' => 'string'],
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

        // Lightweight draft store so a partial onboarding run keeps the goal
        // step's selection across reloads. Read on mount, written on step 2.
        register_rest_route(self::NAMESPACE, '/admin/onboarding/draft', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getDraft'],
                'permission_callback' => [$this, 'canAccess'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'putDraft'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);
    }

    private const DRAFT_OPTION    = 'dono_onboarding_draft';
    private const CAMPAIGN_OPTION = 'dono_onboarding_campaign_id';

    public function getDraft(): WP_REST_Response
    {
        $stored = get_option(self::DRAFT_OPTION, []);
        return new WP_REST_Response(is_array($stored) ? $stored : [], 200);
    }

    public function putDraft(WP_REST_Request $request): WP_REST_Response
    {
        $body = (array) ($request->get_json_params() ?? []);
        $goal = is_array($body['goal'] ?? null) ? $body['goal'] : null;
        $draft = $goal !== null
            ? ['goal' => [
                'mode'   => ($goal['mode'] ?? 'target') === 'ongoing' ? 'ongoing' : 'target',
                'amount' => max(0, (int) ($goal['amount'] ?? 0)),
            ]]
            : [];
        update_option(self::DRAFT_OPTION, $draft, false);
        return new WP_REST_Response($draft, 200);
    }

    public function canAccess(): bool
    {
        return current_user_can('manage_options');
    }

    public function finalize(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) ($request->get_json_params() ?? []);

        // Idempotency: a completed onboarding whose campaign still exists
        // returns that campaign instead of publishing a duplicate on a re-run
        // or a retry after a lost finalize response.
        if (get_option(Onboarding::OPTION) === 'completed') {
            $existingId = (int) get_option(self::CAMPAIGN_OPTION, 0);
            if ($existingId > 0) {
                $existing = Campaign::query()->find('id', $existingId);
                if ($existing) {
                    return $this->finalizeResponse($existing);
                }
            }
        }

        $title = trim((string) ($body['campaign_title'] ?? ''));
        if ($title === '') $title = __('General donations', 'dono');

        $currency = strtoupper(substr((string) ($body['currency'] ?? ''), 0, 3));
        if ($currency === '') {
            $loc = get_option('dono_currency_locale');
            $currency = is_array($loc) ? strtoupper((string) ($loc['default_currency'] ?? 'USD')) : 'USD';
        }

        try {
            $campaign = $this->campaigns->create([
                'title'         => $title,
                'currency'      => $currency,
                'status'        => 'published',
                'goal_type'     => 'amount',
                'goal_cents'    => $this->goalCentsFrom($body),
                // Blank form; the editor shows a template picker on first open.
                'skip_template' => true,
            ]);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        }

        update_option(Onboarding::OPTION, 'completed', false);
        update_option(self::CAMPAIGN_OPTION, (int) $campaign->id, false);
        delete_option(self::DRAFT_OPTION);

        // "Just exploring" starts the org in test mode: nothing takes real
        // money and a test/sandbox gateway is available until they switch
        // test mode off in Settings, Payment gateways.
        if (trim((string) ($body['user_type'] ?? '')) === 'exploring') {
            $this->settings->update('gateways', ['test_mode' => true]);
        }

        return $this->finalizeResponse($campaign);
    }

    /**
     * Fundraising goal from the wizard's step-2 selection: the finalize
     * payload, or the persisted draft as a fallback when the client omitted
     * it. A 'target' goal with a positive amount maps to goal_cents
     * (major x 100); ongoing collection leaves the campaign without a target.
     *
     * @param array<string,mixed> $body
     */
    private function goalCentsFrom(array $body): ?int
    {
        $mode   = (string) ($body['goal_mode'] ?? '');
        $amount = (int) ($body['goal_amount'] ?? 0);
        if ($mode === '') {
            $draft = get_option(self::DRAFT_OPTION, []);
            $goal  = is_array($draft) ? ($draft['goal'] ?? null) : null;
            if (is_array($goal)) {
                $mode   = (string) ($goal['mode'] ?? '');
                $amount = (int) ($goal['amount'] ?? 0);
            }
        }
        return $mode === 'target' && $amount > 0 ? $amount * 100 : null;
    }

    private function finalizeResponse(Campaign $campaign): WP_REST_Response
    {
        $formId = (int) ($campaign->default_form_id ?? 0);

        return new WP_REST_Response([
            'ok'              => true,
            'campaign_id'     => (int) $campaign->id,
            'campaign_slug'   => (string) $campaign->slug,
            'campaign_page'   => $campaign->page_id ? (string) get_permalink((int) $campaign->page_id) : '',
            'default_form_id' => $formId,
            'form_edit_url'   => $formId > 0
                ? esc_url_raw(admin_url('admin.php?page=dono-forms&form=' . $formId))
                : '',
        ], 200);
    }

    public function dismiss(): WP_REST_Response
    {
        update_option(Onboarding::OPTION, 'dismissed', false);
        return new WP_REST_Response(['ok' => true], 200);
    }
}
