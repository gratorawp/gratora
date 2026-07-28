<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\GiftAid\GiftAidClaimExport;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The Gift Aid claim: a summary an operator reads, and the file they submit.
 *
 * Gated on the export capability rather than the view one. The file is bulk PII
 * with home addresses in it, which is a stronger disclosure than the donations
 * list, and it is the same gate the donor CSV export uses.
 *
 * @version 1.0.0
 */
final class GiftAidController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(private GiftAidClaimExport $export)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/gift-aid/summary', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'summary'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_view_reports'),
            'args'                => self::range(),
        ]);

        register_rest_route(self::NAMESPACE, '/admin/gift-aid/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_export_donors'),
            'args'                => self::range(),
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private static function range(): array
    {
        return [
            'from' => ['type' => 'string', 'required' => true],
            'to'   => ['type' => 'string', 'required' => true],
        ];
    }

    /**
     * What the claim would contain, so an operator can see the incomplete
     * records before they submit rather than after HMRC rejects the schedule.
     */
    public function summary(WP_REST_Request $request): WP_REST_Response
    {
        [$from, $to] = $this->bounds($request);
        $built       = $this->export->build($from, $to);

        return new WP_REST_Response([
            'from'          => $from,
            'to'            => $to,
            'rows'          => $built['rows'],
            'skipped'       => $built['skipped'],
            'amount_cents'  => $built['amount_cents'],
            'reclaim_cents' => GiftAidClaimExport::reclaimCents($built['amount_cents']),
        ], 200);
    }

    public function download(WP_REST_Request $request): WP_REST_Response
    {
        [$from, $to] = $this->bounds($request);
        $csv         = $this->export->build($from, $to)['csv'];
        $filename    = 'dono-gift-aid-' . substr($from, 0, 10) . '-to-' . substr($to, 0, 10) . '.csv';
        $route       = $request->get_route();

        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $csv, $filename) {
            if ((string) $req->get_route() !== $route) return $served;
            $server->send_header('Content-Type', 'text/csv; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            echo $csv;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
        return $response;
    }

    /**
     * A bare date means the whole of that day, so "to = today" includes gifts
     * made this morning rather than stopping at midnight.
     *
     * @return array{0:string,1:string}
     */
    private function bounds(WP_REST_Request $request): array
    {
        $from = trim((string) $request['from']);
        $to   = trim((string) $request['to']);

        if (strlen($from) <= 10) $from .= ' 00:00:00';
        if (strlen($to)   <= 10) $to   .= ' 23:59:59';

        return [$from, $to];
    }
}
