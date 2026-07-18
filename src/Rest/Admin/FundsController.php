<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Funds\Fund;
use Dono\Funds\FundReassignmentJob;
use Dono\Funds\FundRepository;
use Dono\Funds\FundService;
use Dono\Rest\Schemas\FundSchemas;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Admin CRUD surface for funds (organisation-wide donation designations). */
final class FundsController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private FundRepository $funds,
        private FundService $fundService,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/funds', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                    'orderby'  => ['type' => 'string'],
                    'order'    => ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']],
                    'status'   => ['type' => 'string', 'enum' => ['active', 'inactive', 'restricted']],
                    'search'   => ['type' => 'string'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => FundSchemas::create(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/funds/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'stats'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/funds/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => FundSchemas::update(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'reassign_to' => ['type' => ['integer', 'null'], 'minimum' => 1],
                ],
            ],
        ]);
    }

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_campaigns');
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        // Re-queue any reassignment whose background job was lost so a stale
        // "Reassigning…" badge can't get stuck. Idempotent and cheap.
        $this->fundService->reconcilePendingReassignments();

        $perPage = (int) ($request['per_page'] ?? 25);

        $result = $this->funds->listAdmin([
            'page'     => (int) ($request['page'] ?? 1),
            'per_page' => $perPage,
            'orderby'  => (string) ($request['orderby'] ?? 'sort_order'),
            'order'    => (string) ($request['order'] ?? 'asc'),
            'status'   => $request['status'] !== null ? (string) $request['status'] : null,
            'search'   => $request['search'] !== null ? (string) $request['search'] : null,
        ]);

        // donations_count is denormalised on the fund row (written by
        // AggregateSyncer::syncFund). Pass null so shape() reads the column.
        $deletable = $this->fundService->deletableMap(
            array_map(static fn (Fund $f) => (int) $f->id, $result['items'])
        );
        $shaped = array_map(
            fn (Fund $f) => $this->shape($f, null, $deletable[(int) $f->id] ?? false),
            $result['items']
        );

        $response = new WP_REST_Response($shaped, 200);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));
        return $response;
    }

    public function stats(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->funds->stats(), 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $fund = $this->funds->findById((int) $request['id']);
        if (! $fund) {
            return new WP_Error('dono_not_found', __('Fund not found.', 'dono'), ['status' => 404]);
        }
        return new WP_REST_Response($this->shapeOne($fund), 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) ($request->get_json_params() ?? []);
        try {
            $fund = $this->fundService->create($body);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_fund_create_failed', $e->getMessage(), ['status' => 500]);
        }
        return new WP_REST_Response($this->shapeOne($fund), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $fund = $this->funds->findById((int) $request['id']);
        if (! $fund) {
            return new WP_Error('dono_not_found', __('Fund not found.', 'dono'), ['status' => 404]);
        }
        $body = (array) ($request->get_json_params() ?? []);
        try {
            $fund = $this->fundService->update($fund, $body);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        }
        return new WP_REST_Response($this->shapeOne($fund), 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $fund = $this->funds->findById((int) $request['id']);
        if (! $fund) {
            return new WP_Error('dono_not_found', __('Fund not found.', 'dono'), ['status' => 404]);
        }
        $reassignTo = $request['reassign_to'] !== null ? (int) $request['reassign_to'] : null;

        try {
            $result = $this->fundService->delete($fund, $reassignTo);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_fund_delete_blocked', $e->getMessage(), ['status' => 422]);
        }

        $status = $result['action'] === 'reassign_queued' ? 202 : 200;
        return new WP_REST_Response(['id' => (int) $fund->id] + $result, $status);
    }

    /** @return array<string,mixed> */
    private function shapeOne(Fund $f): array
    {
        $deletable = $this->fundService->deletableMap([(int) $f->id]);
        return $this->shape($f, null, $deletable[(int) $f->id] ?? false);
    }

    private function shape(Fund $f, ?int $donationsCount = null, bool $deletable = false): array
    {
        return [
            'id'              => (int) $f->id,
            'code'            => $f->code,
            'name'            => $f->name,
            'description'     => $f->description,
            'is_restricted'   => (bool) $f->is_restricted,
            'is_default'      => (bool) $f->is_default,
            'is_active'       => (bool) $f->is_active,
            'sort_order'      => (int) $f->sort_order,
            'parent_fund_id'  => $f->parent_fund_id !== null ? (int) $f->parent_fund_id : null,
            'goal_cents'      => $f->goal_cents !== null ? (int) $f->goal_cents : null,
            'raised_cents'    => (int) $f->raised_cents,
            'donors_count'    => (int) $f->donors_count,
            'last_paid_at'    => $f->last_paid_at,
            'starts_at'       => $f->starts_at,
            'ends_at'         => $f->ends_at,
            'accounting_code' => $f->accounting_code,
            'created_at'      => $f->created_at,
            'updated_at'      => $f->updated_at,
            // Prefer the denormalised column written by AggregateSyncer;
            // batch override lets the list endpoint short-circuit a per-row
            // COUNT(*) until the next migration backfills the column.
            'donations_count' => $donationsCount ?? (int) $f->donations_count,
            'reassign_pending' => array_key_exists((int) $f->id, FundReassignmentJob::pending()),
            // Whether delete() would hard-delete (no references) vs deactivate,
            // so the delete dialog offers the action the server will actually
            // take instead of guessing from the live-only donation count.
            'deletable'        => $deletable,
        ];
    }
}
