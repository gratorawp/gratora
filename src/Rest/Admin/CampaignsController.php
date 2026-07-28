<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Rest\Paging;
use Dono\Foundation\Auth\Capabilities;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignMetricsService;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\CampaignService;
use Dono\Forms\Form;
use Dono\Funds\Fund;
use Dono\Funds\FundRepository;
use Dono\Recurring\RecurringCanceller;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Rest\Schemas\CampaignSchemas;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin REST endpoints for campaigns: list, show, create, update, delete,
 * duplicate, metrics, goal context, and fund picker.
 *
 * @version 1.0.0
 */
final class CampaignsController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private CampaignRepository $campaigns,
        private CampaignService $campaignService,
        private CampaignMetricsService $metrics,
        private FundRepository $funds,
        private RecurringPlanRepository $plans,
        private RecurringCanceller $canceller,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/campaigns', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                    'orderby'  => ['type' => 'string', 'default' => 'updated_at'],
                    'order'    => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                    'status'   => ['type' => 'string'],
                    'search'   => ['type' => 'string'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => CampaignSchemas::create(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/funds', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'funds'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'stats'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'status' => ['type' => 'string'],
                'search' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'range' => ['type' => 'string', 'default' => 'all-time'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => CampaignSchemas::update(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/metrics', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'metrics'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'range'   => ['type' => 'string', 'default' => 'all-time'],
                'compare' => [
                    'type'    => 'string',
                    'enum'    => ['none', 'period', 'year'],
                    'default' => 'none',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/duplicate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'duplicate'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/goal-context', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'goalContext'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/campaigns/(?P<id>\d+)/recurring-summary', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'recurringSummary'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    /**
     * Active recurring plans + monthly-equivalent for a campaign. Drives the
     * archive confirmation ("N active recurring donations that keep renewing").
     */
    public function recurringSummary(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $campaign = $this->campaigns->findById((int) $request['id']);
        if (! $campaign) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }
        $summary = $this->plans->activeForCampaign((int) $campaign->id);
        return new WP_REST_Response([
            'count'     => $summary['count'],
            'mrr_cents' => $summary['mrr_cents'],
            'currency'  => $campaign->currency,
        ], 200);
    }

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_campaigns');
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $perPage = (int) ($request['per_page'] ?? 25);

        $result = $this->campaigns->listAdmin([
            'page'     => Paging::page($request['page'] ?? null),
            'per_page' => $perPage,
            'orderby'  => (string) ($request['orderby'] ?? 'updated_at'),
            'order'    => (string) ($request['order']   ?? 'desc'),
            'status'   => $request['status'] !== null ? (string) $request['status'] : null,
            'search'   => $request['search'] !== null ? (string) $request['search'] : null,
        ]);

        $shaped = array_map(fn (Campaign $c) => $this->shapeSummary($c), $result['items']);

        $response = new WP_REST_Response($shaped, 200);
        $response->header('X-WP-Total',      (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));
        return $response;
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $campaign = $this->campaigns->findById((int) $request['id']);
        if (! $campaign) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }
        return new WP_REST_Response($this->shapeFull($campaign, (string) ($request['range'] ?? 'all-time')), 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) ($request->get_json_params() ?? []);
        try {
            $campaign = $this->campaignService->create($body);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_campaign_create_failed', $e->getMessage(), ['status' => 500]);
        }
        return new WP_REST_Response($this->shapeFull($campaign, 'all-time'), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $campaign = $this->campaigns->findById((int) $request['id']);
        if (! $campaign) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }
        $body      = (array) ($request->get_json_params() ?? []);
        $wasActive = $campaign->status !== 'archived';
        try {
            $campaign = $this->campaignService->update($campaign, $body);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        }

        // Archiving is non-destructive to subscriptions by default: existing
        // recurring donations keep renewing (and are still credited here). The
        // admin can opt to stop them too via the archive dialog's checkbox.
        $recurringCancel = null;
        if ($wasActive && $campaign->status === 'archived' && ! empty($body['cancel_recurring'])) {
            $recurringCancel = $this->canceller->cancelActiveForCampaign(
                (int) $campaign->id,
                __('Campaign archived', 'dono')
            );
        }

        $payload = $this->shapeFull($campaign, 'all-time');
        if ($recurringCancel !== null) {
            $payload['recurring_cancel'] = $recurringCancel;
        }

        return new WP_REST_Response($payload, 200);
    }

    /**
     * Historical raised totals for non-archived campaigns other than the
     * current one. Drives the ambition meter on the Goal sub-tab.
     */
    public function goalContext(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $current = $this->campaigns->findById((int) $request['id']);
        if (! $current) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }

        $others = Campaign::query()
            ->where('id', $current->id, '!=')
            ->whereIn('status', ['published', 'draft'])
            ->getAll();

        $totals = array_values(array_filter(
            array_map(fn (Campaign $c) => (int) ($c->raised_cents ?? 0), $others),
            fn (int $v) => $v > 0,
        ));

        $count   = count($totals);
        $avg     = $count > 0 ? (int) round(array_sum($totals) / $count) : 0;
        $max     = $count > 0 ? (int) max($totals) : 0;
        $current_target = (int) ($current->goal_cents ?? 0);

        $verdict = $this->verdict($current_target, $avg, $count);

        return new WP_REST_Response([
            'historical_count'      => $count,
            'historical_avg_cents'  => $avg,
            'historical_max_cents'  => $max,
            'current_target_cents'  => $current_target,
            'currency'              => $current->currency ?: 'USD',
            'verdict'               => $verdict,
        ], 200);
    }

    private function verdict(int $target, int $avg, int $sampleCount): string
    {
        if ($sampleCount === 0)         return 'first_campaign';
        if ($target <= 0)               return 'no_target';
        if ($avg <= 0)                  return 'in_line';
        $ratio = $target / $avg;
        if ($ratio < 0.5)               return 'modest';
        if ($ratio < 1.5)               return 'in_line';
        if ($ratio < 3.0)               return 'ambitious';
        return 'very_ambitious';
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $source = $this->campaigns->findById((int) $request['id']);
        if (! $source) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }
        try {
            $copy = $this->campaignService->duplicate($source);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_campaign_duplicate_failed', $e->getMessage(), ['status' => 500]);
        }
        return new WP_REST_Response($this->shapeFull($copy, 'all-time'), 201);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $campaign = $this->campaigns->findById((int) $request['id']);
        if (! $campaign) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }
        try {
            $this->campaignService->delete($campaign);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_campaign_delete_blocked', $e->getMessage(), ['status' => 422]);
        }
        return new WP_REST_Response(['deleted' => true, 'id' => $campaign->id], 200);
    }

    public function metrics(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $campaign = $this->campaigns->findById((int) $request['id']);
        if (! $campaign) {
            return new WP_Error('dono_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }
        $range   = (string) ($request['range']   ?? 'all-time');
        $compare = (string) ($request['compare'] ?? 'none');

        // ?include= comma-separated widget keys. Absent = compute all
        // (back-compat); present but empty = compute nothing.
        $include = null;
        if ($request['include'] !== null) {
            $include = array_values(array_filter(array_map('trim', explode(',', (string) $request['include']))));
        }

        return new WP_REST_Response($this->buildMetrics($campaign, $range, $compare, $include), 200);
    }

    public function stats(WP_REST_Request $request): WP_REST_Response
    {
        $stats = $this->campaigns->aggregateAdmin([
            'status' => $request['status'] !== null ? (string) $request['status'] : null,
            'search' => $request['search'] !== null ? (string) $request['search'] : null,
        ]);
        return new WP_REST_Response($stats, 200);
    }

    public function funds(): WP_REST_Response
    {
        $rows = Fund::query()->where('is_active', 1)->orderBy('sort_order', 'ASC')->getAll();
        $shaped = array_map(
            fn (Fund $f): array => [
                'id'         => $f->id,
                'code'       => $f->code,
                'name'       => $f->name,
                'is_default' => (bool) $f->is_default,
            ],
            $rows
        );
        return new WP_REST_Response($shaped, 200);
    }

    private function shapeSummary(Campaign $c): array
    {
        $formsCount = (int) Form::query()->where('campaign_id', $c->id)->count();
        $imageUrl   = $c->image_attachment_id ? wp_get_attachment_image_url($c->image_attachment_id, 'large') : null;
        return [
            'id'                  => $c->id,
            'title'               => $c->title,
            'slug'                => $c->slug,
            'status'              => $c->status,
            'campaign_type'       => $c->campaign_type,
            'campaign_type_label' => $c->campaign_type === 'standard' ? '' : (string) (
                ((array) apply_filters('dono.campaign.types', ['standard' => '']))[$c->campaign_type]
                    ?? ucfirst(str_replace('_', ' ', $c->campaign_type))
            ),
            'currency'            => $c->currency,
            'goal_type'           => $c->goal_type,
            'goal_cents'          => $c->goal_cents,
            'goal_count'          => $c->goal_count,
            'raised_cents'        => $c->raised_cents,
            'donations_count'     => $c->donations_count,
            'donors_count'        => $c->donors_count,
            'forms_count'         => $formsCount,
            'page_id'             => $c->page_id,
            'default_form_id'     => $c->default_form_id,
            'default_fund_id'     => $c->default_fund_id,
            'style'               => is_array($c->style) ? $c->style : null,
            'hide_header'         => (bool) $c->hide_header,
            'hide_footer'         => (bool) $c->hide_footer,
            'accent'              => $c->accentColor(),
            'image_attachment_id' => $c->image_attachment_id,
            'image_url'           => $imageUrl,
            'updated_at'          => $c->updated_at,
            'created_at'          => $c->created_at,
        ];
    }

    private function shapeFull(Campaign $c, string $range): array
    {
        $pageEditUrl = $c->page_id ? admin_url('post.php?post=' . $c->page_id . '&action=edit') : null;
        $pageUrl     = $c->page_id ? get_permalink((int) $c->page_id) : false;

        // Metrics excluded: multi-second aggregate; the Overview tab fetches
        // /admin/campaigns/{id}/metrics separately so this path stays instant.
        unset($range);
        return $this->shapeSummary($c) + [
            'description'   => $c->description,
            'starts_at'     => $c->starts_at,
            'ends_at'       => $c->ends_at,
            'page_edit_url' => $pageEditUrl,
            'page_url'      => $pageUrl ?: null,
        ];
    }

    /**
     * Only the sections in $include are aggregated; absent keys come back
     * null/empty. `null` $include computes everything (legacy behaviour).
     *
     * @param array<int,string>|null $include  Widget keys the client currently shows.
     * @return array<string,mixed>
     */
    private function buildMetrics(Campaign $c, string $range, string $compare, ?array $include = null): array
    {
        $want = static function (string ...$keys) use ($include): bool {
            if ($include === null) return true;
            foreach ($keys as $k) {
                if (in_array($k, $include, true)) return true;
            }
            return false;
        };

        // Summary KPIs feed both the kpis widget and the revenue comparison line.
        $needSummary = $want('kpis', 'revenue');

        $payload = ['range' => $range];

        if ($needSummary) {
            $payload += $this->metrics->summaryWithComparison($c->id, $range, $compare);
        }
        if ($want('revenue')) {
            $payload['revenue_series'] = $this->metrics->revenueSeries($c->id, $range);
        }
        if ($want('recent')) {
            $payload['recent_donations'] = $this->metrics->recentDonations($c->id, 5);
        }
        if ($want('top-donors')) {
            $payload['top_donors'] = $this->metrics->topDonors($c->id, 5, $range);
        }
        if ($want('top-forms')) {
            $payload['top_forms'] = $this->metrics->topForms($c->id, $range, 5);
        }
        if ($want('gateway')) {
            $payload['by_gateway'] = $this->metrics->byGateway($c->id, $range);
        }
        if ($want('timeline')) {
            $payload['timeline'] = $this->metrics->timeline($c);
        }
        if ($want('cohort')) {
            $payload['cohort'] = $this->metrics->cohort($c->id, $range);
        }
        if ($want('stories')) {
            $payload['notes'] = $this->metrics->notes($c->id, 6);
        }
        if ($want('distribution')) {
            $payload['distribution'] = $this->metrics->distributionBuckets($c->id, $range);
        }
        if ($want('heatmap')) {
            $payload['dow_hour'] = $this->metrics->dowHourGrid($c->id, $range);
        }
        if ($want('channel')) {
            $payload['by_channel'] = $this->metrics->byChannel($c->id, $range);
        }

        return $payload;
    }
}
