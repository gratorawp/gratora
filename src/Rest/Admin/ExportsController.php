<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\Campaign;
use Dono\Donations\DonationRepository;
use Dono\Exports\DonorExporter;
use Dono\Foundation\Auth\Capabilities;
use Dono\Exports\RevenueExporter;
use Dono\Reports\RevenueReportBuilder;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Bulk data exports for the Tools screen.
 *
 * Each route carries the capability of the data it emits, not the capability of
 * the screen it is reached from: the donor list is bulk PII and needs the
 * export cap, while the revenue figures are aggregates and need only reports.
 *
 * @since 1.0.0
 */
final class ExportsController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private DonorExporter $donors,
        private RevenueExporter $revenue,
        private RevenueReportBuilder $revenueReport,
        private DonationRepository $donations,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/exports/options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'options'],
            'permission_callback' => static fn (): bool => Capabilities::userCan('dono_view_reports')
                || Capabilities::userCan('dono_export_donors'),
        ]);

        register_rest_route(self::NAMESPACE, '/admin/exports/donors\.csv', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'donorsCsv'],
            'permission_callback' => static fn (): bool => Capabilities::userCan('dono_export_donors'),
            'args'                => [
                'from'        => ['type' => 'string', 'default' => ''],
                'to'          => ['type' => 'string', 'default' => ''],
                'campaign_id' => ['type' => 'integer', 'default' => 0],
                'columns'     => ['type' => 'string', 'default' => ''],
                // Mirrors the donor list's own filters so a segment you can see
                // on screen is a segment you can take away with you.
                'country'     => ['type' => 'string', 'default' => ''],
                'donor_type'  => ['type' => 'string', 'default' => '', 'enum' => ['', 'individual', 'organization', 'company', 'household']],
                'search'      => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/exports/revenue\.csv', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'revenueCsv'],
            'permission_callback' => static fn (): bool => Capabilities::userCan('dono_view_reports'),
            'args'                => [
                'from' => ['type' => 'string', 'default' => ''],
                'to'   => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/exports/revenue\.pdf', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'revenuePdf'],
            'permission_callback' => static fn (): bool => Capabilities::userCan('dono_view_reports'),
            'args'                => [
                'year' => ['type' => 'integer', 'default' => 0],
            ],
        ]);
    }

    /**
     * What the Export screen needs to build its controls.
     *
     * @since 1.0.0
     */
    public function options(): WP_REST_Response
    {
        $thisYear = (int) wp_date('Y');

        // Bound the pickers by the data. Offering a fixed span of past years
        // invites someone to export a decade of months that never had a
        // donation in them and read the empty rows as a fault.
        $firstPaid = $this->donations->firstPaidDate();
        $firstYear = $firstPaid !== null ? (int) substr($firstPaid, 0, 4) : $thisYear;
        $firstYear = max(2000, min($firstYear, $thisYear));

        return new WP_REST_Response([
            'donor_columns'  => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => DonorExporter::labels()[$key] ?? $key],
                DonorExporter::COLUMNS
            ),
            // Served here rather than from the donations controller's picker:
            // that one needs view-donations, which a role created purely to
            // pull the donor list has no reason to hold.
            'campaigns'      => array_map(
                static fn (Campaign $c): array => ['id' => (int) $c->id, 'title' => (string) $c->title],
                Campaign::query()
                    ->whereIn('status', ['published', 'archived'])
                    ->orderBy('created_at', 'DESC')
                    ->limit(500)
                    ->getAll()
            ),
            'years'          => range($thisYear, $firstYear),
            'current_year'   => $thisYear,
            'current_month'  => (string) wp_date('Y-m'),
            'first_month'    => $firstPaid !== null ? substr($firstPaid, 0, 7) : (string) wp_date('Y-m'),
            'can_export_donors' => Capabilities::userCan('dono_export_donors'),
            'can_view_reports'  => Capabilities::userCan('dono_view_reports'),
        ], 200);
    }

    /** @since 1.0.0 */
    public function donorsCsv(WP_REST_Request $request): WP_REST_Response
    {
        $columns = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request['columns'])
        )));

        $csv = $this->donors->toCsv([
            'columns'     => $columns,
            'from'        => (string) $request['from'],
            'to'          => (string) $request['to'],
            'campaign_id' => (int) $request['campaign_id'],
            'country'     => (string) $request['country'],
            'donor_type'  => (string) $request['donor_type'],
            'search'      => (string) $request['search'],
        ]);

        return $this->streamCsv($request, $csv, DonorExporter::filename());
    }

    /** @since 1.0.0 */
    public function revenueCsv(WP_REST_Request $request): WP_REST_Response
    {
        $from = (string) $request['from'] ?: (string) wp_date('Y-01');
        $to   = (string) $request['to']   ?: (string) wp_date('Y-m');

        return $this->streamCsv(
            $request,
            $this->revenue->toCsv($from, $to),
            RevenueExporter::filename($from, $to)
        );
    }

    /** @since 1.0.0 */
    public function revenuePdf(WP_REST_Request $request): WP_REST_Response
    {
        $year = (int) $request['year'] ?: (int) wp_date('Y');
        $year = max(2000, min((int) wp_date('Y'), $year));

        return $this->stream(
            $request,
            $this->revenueReport->build($year),
            RevenueReportBuilder::filename($year),
            'application/pdf'
        );
    }

    /** @since 1.0.0 */
    private function streamCsv(WP_REST_Request $request, string $body, string $filename): WP_REST_Response
    {
        return $this->stream($request, $body, $filename, 'text/csv; charset=utf-8');
    }

    /**
     * The REST server would JSON-encode a binary or CSV body, so the bytes are
     * echoed from a rest_pre_serve_request closure bound to this route.
     *
     * @since 1.0.0
     */
    private function stream(WP_REST_Request $request, string $body, string $filename, string $type): WP_REST_Response
    {
        $route = $request->get_route();

        add_filter('rest_pre_serve_request', static function (bool $served, $result, $req, $server) use ($route, $body, $filename, $type) {
            if ((string) $req->get_route() !== $route) {
                return $served;
            }
            $server->send_header('Content-Type', $type);
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            // Bytes of a file being downloaded, sent under their own Content-Type
            // header. Escaping them would corrupt the document.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $body;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => $type,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
        $response->set_data($body);

        return $response;
    }
}
