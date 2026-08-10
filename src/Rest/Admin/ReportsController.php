<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\CampaignRepository;
use Dono\Donors\DonorRepository;
use Dono\Reports\CampaignReportBuilder;
use Dono\Reports\TaxStatementBuilder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Capability-gated report PDFs, regenerated and streamed on demand. Nothing is
 * written to a public URL: the donor tax statement carries PII and is gated on
 * dono_view_donors; the campaign one-pager is aggregate-only and gated on
 * dono_view_reports.
 *
 * A clicked download link carries the operator's auth cookie but not a REST
 * nonce, so the command that builds the link appends ?_wpnonce=wp_create_nonce
 * ('wp_rest') for cookie auth to validate. The link is therefore time-limited to
 * the nonce lifetime.
 *
 * @since 1.0.0
 */
final class ReportsController
{
    private const NAMESPACE = 'dono/v1';

    /** Reporting windows, matching report.dashboard / CampaignMetricsService. */
    private const RANGES = ['today', 'last-7', 'last-30', 'last-90', 'all-time'];

    /** @since 1.0.0 */
    public function __construct(
        private CampaignRepository $campaigns,
        private CampaignReportBuilder $campaignReport,
        private DonorRepository $donors,
        private TaxStatementBuilder $taxStatement,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/reports/campaign/(?P<id>\d+)/pdf', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'campaignPdf'],
            'permission_callback' => [$this, 'canViewReports'],
            'args'                => [
                'id'    => ['type' => 'integer'],
                'range' => ['type' => 'string', 'enum' => self::RANGES, 'default' => 'last-30'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/reports/donor/(?P<id>\d+)/tax-statement/(?P<year>\d{4})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'donorTaxStatement'],
            'permission_callback' => [$this, 'canViewDonors'],
            'args'                => [
                'id'   => ['type' => 'integer'],
                'year' => ['type' => 'integer'],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function canViewReports(): bool
    {
        return current_user_can('dono_view_reports');
    }

    /** @since 1.0.0 */
    public function canViewDonors(): bool
    {
        return current_user_can('dono_view_donors');
    }

    /** @since 1.0.0 */
    public function campaignPdf(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $campaign = $this->campaigns->findById((int) $request['id']);
        if (! $campaign) {
            return new WP_Error('dono_campaign_not_found', __('Campaign not found.', 'dono'), ['status' => 404]);
        }

        $range = (string) ($request['range'] ?? 'last-30');
        if (! in_array($range, self::RANGES, true)) {
            $range = 'last-30';
        }

        $pdf = $this->campaignReport->build($campaign, $range);

        return $this->stream($request, $pdf, CampaignReportBuilder::filename((int) $campaign->id, $range));
    }

    /** @since 1.0.0 */
    public function donorTaxStatement(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $year = (int) $request['year'];
        if ($year < 2000 || $year > (int) wp_date('Y')) {
            return new WP_Error('dono_invalid_year', __('Unsupported statement year.', 'dono'), ['status' => 422]);
        }

        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor || $donor->redacted_at !== null) {
            return new WP_Error('dono_donor_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }

        $pdf = $this->taxStatement->build($donor, $year);
        if ($pdf === '') {
            return new WP_Error('dono_no_donations', __('No donations found for that year.', 'dono'), ['status' => 404]);
        }

        return $this->stream($request, $pdf, TaxStatementBuilder::filename((int) $donor->id, $year));
    }

    /**
     * Stream PDF bytes as a download. The REST server would JSON-encode a binary
     * body, so the bytes are echoed from a rest_pre_serve_request closure bound
     * to this route.
     *
     * @since 1.0.0
     */
    private function stream(WP_REST_Request $request, string $pdf, string $filename): WP_REST_Response
    {
        $route = $request->get_route();
        add_filter('rest_pre_serve_request', static function (bool $served, $result, $req, $server) use ($route, $pdf, $filename) {
            if ((string) $req->get_route() !== $route) {
                return $served;
            }
            $server->send_header('Content-Type', 'application/pdf');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            echo $pdf;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
        return $response;
    }
}
