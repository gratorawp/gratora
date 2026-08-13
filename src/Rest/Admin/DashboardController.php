<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Dashboard\DashboardMetricsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin dashboard metrics endpoint with widget-key filtering.
 *
 * @since 1.0.0
 */
final class DashboardController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(private DashboardMetricsService $metrics)
    {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/dashboard', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'range'   => ['type' => 'string', 'default' => 'last-30'],
                'compare' => [
                    'type'    => 'string',
                    'enum'    => ['none', 'period', 'year'],
                    'default' => 'none',
                ],
                'include' => ['type' => 'string'],
                // Off by default, as on every other screen: the figures here
                // are the ones an operator quotes, so test money is opt-in.
                'include_test' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_view_reports');
    }

    /** @since 1.0.0 */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $range   = (string) ($request['range']   ?? 'last-30');
        $compare = (string) ($request['compare'] ?? 'none');

        // ?include= comma-separated widget keys. Absent = compute all;
        // present but empty = compute nothing.
        $include = null;
        if ($request['include'] !== null) {
            $include = array_values(array_filter(array_map('trim', explode(',', (string) $request['include']))));
        }
        $want = static function (string ...$keys) use ($include): bool {
            if ($include === null) return true;
            foreach ($keys as $k) {
                if (in_array($k, $include, true)) return true;
            }
            return false;
        };

        $includeTest = (bool) $request['include_test'];

        // Outside the $want() gates: a widget filter must never be able to drop
        // the thing that explains why the numbers look the way they do.
        $payload = [
            'range' => $range,
            'test'  => [
                'includes_test' => $includeTest,
                'hidden'        => $this->metrics->hiddenTestCount(),
            ],
        ];

        if ($want('kpis'))             $payload['kpi']              = $this->metrics->kpi($range, $compare, $includeTest);
        if ($want('revenue'))          $payload['revenue']          = $this->metrics->revenueSeries($range, $compare, $includeTest);
        if ($want('active-campaigns')) $payload['active_campaigns'] = $this->metrics->activeCampaigns(6, $includeTest);
        if ($want('top-campaigns'))    $payload['top_campaigns']    = $this->metrics->topCampaigns($range, 5, $includeTest);
        if ($want('channel'))          $payload['by_channel']       = $this->metrics->byChannel($range, $includeTest);
        if ($want('recurring'))        $payload['recurring']        = $this->metrics->recurring($includeTest);
        if ($want('today'))            $payload['today']            = $this->metrics->today($includeTest);
        if ($want('recent-activity'))  $payload['recent_activity']  = $this->metrics->recentActivity(8, $includeTest);
        if ($want('attention'))        $payload['attention']        = $this->metrics->attention($includeTest);

        return new WP_REST_Response($payload, 200);
    }
}
