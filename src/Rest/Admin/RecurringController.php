<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\CampaignRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentRetryUnavailable;
use Dono\Gateways\SubscriptionChangeNeedsApproval;
use Dono\Gateways\SupportsPaymentRetry;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;
use Dono\Recurring\RecurringPlanRepository;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The admin side of a recurring plan: pause, resume, skip, re-price and cancel,
 * gated on the capability that already governs changing what a card is charged.
 *
 * The work itself lives in RecurringPlanActions, shared with the portal.
 *
 * @since 1.0.0
 */
final class RecurringController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private RecurringPlanActions $actions,
        private RecurringPlanRepository $plans,
        private DonorRepository $donors,
        private DonorService $donorService,
        private CampaignRepository $campaigns,
        private GatewayManager $gateways,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/recurring', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => static fn () => Capabilities::userCan('dono_view_donations'),
            'callback'            => [$this, 'index'],
            'args'                => [
                'page'         => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page'     => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                'orderby'      => ['type' => 'string', 'default' => 'next_payment_at'],
                'order'        => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                'status'       => ['type' => 'string'],
                'gateway'      => ['type' => 'string'],
                'campaign_id'  => ['type' => 'integer', 'minimum' => 1],
                'interval'     => ['type' => 'string', 'enum' => ['week', 'month', 'year']],
                // Plans the gateway could not collect from, whatever their status.
                'failing'      => ['type' => 'boolean', 'default' => false],
                'search'       => ['type' => 'string'],
                'include_test' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/recurring/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => static fn () => Capabilities::userCan('dono_view_donations'),
            'callback'            => [$this, 'stats'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/recurring/gateway-options', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => static fn () => Capabilities::userCan('dono_view_donations'),
            'callback'            => [$this, 'gatewayOptions'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/recurring/(?P<id>\d+)/action', [
            'methods'             => WP_REST_Server::CREATABLE,
            // The same authority as a refund: both change what the donor is
            // charged, rather than only annotating a record.
            'permission_callback' => static fn () => Capabilities::userCan('dono_refund_donations'),
            'callback'            => [$this, 'act'],
            'args'                => [
                'action'       => ['type' => 'string', 'required' => true],
                'amount_cents' => ['type' => 'integer', 'minimum' => 1],
                'months'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                'reason'       => ['type' => 'string'],
                'notify_donor' => ['type' => 'boolean', 'default' => true],
            ],
        ]);
    }

    /**
     * The org-wide plan list, not scoped to a single donor.
     *
     * @since 1.0.0
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'status'       => $request['status']      !== null ? (string) $request['status'] : null,
            'gateway'      => $request['gateway']     !== null ? (string) $request['gateway'] : null,
            'campaign_id'  => $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
            'interval'     => $request['interval']    !== null ? (string) $request['interval'] : null,
            'failing'      => (bool) $request['failing'],
            'include_test' => (bool) $request['include_test'],
            // Donor identity is encrypted, so a LIKE over the donors table
            // cannot work. Matching ids are resolved through the hasher first
            // and the plan query filters on them.
            'donor_ids'    => $this->donorIdsMatching((string) ($request['search'] ?? '')),
            'search'       => trim((string) ($request['search'] ?? '')),
        ];

        $perPage = (int) $request['per_page'];
        $page    = (int) $request['page'];

        $total = $this->plans->countAdmin($args);
        $rows  = $this->plans->listAdmin($args, [
            'orderby' => (string) $request['orderby'],
            'order'   => (string) $request['order'],
            'limit'   => $perPage,
            'offset'  => ($page - 1) * $perPage,
        ]);

        $shaped = array_map(fn (RecurringPlan $p): array => $this->shape($p), $rows);

        $response = new WP_REST_Response($shaped, 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / max(1, $perPage))));

        return $response;
    }

    /** @since 1.0.0 */
    public function stats(): WP_REST_Response
    {
        return new WP_REST_Response(
            $this->plans->recurringStats(gmdate('Y-m-d H:i:s')),
            200
        );
    }

    /**
     * Gateways taken from the rows, not the registry: a slug outlives the
     * gateway being disconnected, and an imported plan carries slugs core never
     * registers, so a registry-built filter would offer options that match
     * nothing and hide plans that exist.
     *
     * @since 1.0.0
     */
    public function gatewayOptions(): WP_REST_Response
    {
        return new WP_REST_Response($this->plans->gatewaysInUse(), 200);
    }

    /**
     * @return list<int> Donor ids whose name or address matches the term.
     *
     * @since 1.0.0
     */
    private function donorIdsMatching(string $search): array
    {
        $search = trim($search);

        return $search === '' ? [] : $this->donorService->findIdsBySearch($search);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function shape(RecurringPlan $p): array
    {
        $donor    = $p->donor_id ? $this->donors->findById((int) $p->donor_id) : null;
        $campaign = $p->campaign_id ? $this->campaigns->findById((int) $p->campaign_id) : null;

        return [
            'id'                      => (int) $p->id,
            'gateway'                 => (string) $p->gateway,
            'gateway_subscription_id' => (string) $p->gateway_subscription_id,
            'amount_cents'            => (int) $p->amount_cents,
            'currency'                => (string) $p->currency,
            'interval_unit'           => (string) $p->interval_unit,
            'interval_count'          => (int) $p->interval_count,
            'status'                  => (string) $p->status,
            'started_at'              => $p->started_at,
            'next_payment_at'         => $p->next_payment_at,
            'last_payment_at'         => $p->last_payment_at,
            'resume_at'               => $p->resume_at,
            'cancelled_at'            => $p->cancelled_at,
            'payments_count'          => (int) $p->payments_count,
            'total_paid_cents'        => (int) $p->total_paid_cents,
            'failed_renewals_count'   => (int) $p->failed_renewals_count,
            // PayPal owns its own retry schedule and exposes no endpoint for it,
            // so the action is offered per gateway rather than per status.
            'can_retry'               => $this->gateways->get((string) $p->gateway) instanceof SupportsPaymentRetry,
            'is_test'                 => (bool) $p->is_test,
            'donor'                   => $donor ? [
                'id'   => (int) $donor->id,
                'name' => $this->donorName($donor),
                // Erasure took the address; the row must not hand one back, and
                // its actions need to see there is nobody left to email.
                'redacted' => $donor->redacted_at !== null,
                'email'    => $donor->redacted_at === null ? $this->donorService->decryptEmail($donor) : null,
            ] : null,
            'campaign' => $campaign ? [
                'id'    => (int) $campaign->id,
                'title' => (string) $campaign->title,
            ] : null,
        ];
    }

    /** @since 1.0.0 */
    private function donorName(Donor $d): string
    {
        $full = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''));

        return $full !== '' ? $full : '-';
    }

    /** @since 1.0.0 */
    public function act(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $plan = RecurringPlan::query()->find('id', (int) $request['id']);
        if (! $plan) {
            return new WP_Error('dono_not_found', __('Recurring plan not found.', 'dono'), ['status' => 404]);
        }

        $action = (string) $request['action'];
        // Default true: changing what a card is charged without telling the
        // cardholder is the surprising outcome, so silence has to be chosen.
        $notify = $request['notify_donor'] === null ? true : (bool) $request['notify_donor'];
        $change = RecurringPlanChange::byAdmin($action, $notify);

        try {
            switch ($action) {
                case 'pause':
                    $this->actions->pause($plan, RecurringPlanActions::monthsFromNow((int) ($request['months'] ?? 1)), $change);
                    break;

                case 'resume':
                    $this->actions->resume($plan, $change);
                    break;

                case 'skip_next':
                    $this->actions->skipNext($plan, $change);
                    break;

                case 'change_amount':
                    $this->actions->changeAmount($plan, (int) ($request['amount_cents'] ?? 0), $change);
                    break;

                case 'retry':
                    $this->actions->retryPayment($plan, $change);
                    break;

                case 'cancel':
                    $this->actions->cancel($plan, $request['reason'] !== null ? (string) $request['reason'] : null, $change);
                    break;

                default:
                    return new WP_Error('dono_invalid_action', __('Unknown action.', 'dono'), ['status' => 422]);
            }
        } catch (SubscriptionChangeNeedsApproval $e) {
            // Ahead of RuntimeException, which is its parent. Nothing was
            // written: the processor is waiting on the donor, and recording the
            // new amount here would put the plan permanently out of step with
            // what the card is actually charged.
            return new WP_Error(
                'dono_change_needs_approval',
                __('The payment provider needs the donor to approve this change before it takes effect. Nothing has changed yet.', 'dono'),
                ['status' => 409, 'approve_url' => $e->approveUrl]
            );
        } catch (PaymentRetryUnavailable $e) {
            return new WP_Error('dono_nothing_to_collect', $e->getMessage(), ['status' => 409]);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_plan_terminal', $e->getMessage(), ['status' => 422]);
        } catch (\Throwable $e) {
            \Dono\Analytics\ErrorLog::record('admin.recurring', $e->getMessage());
            return new WP_Error(
                'dono_gateway_error',
                __('The payment provider would not accept that change. Nothing has been altered.', 'dono'),
                ['status' => 502]
            );
        }

        return new WP_REST_Response([
            'id'              => (int) $plan->id,
            'status'          => (string) $plan->status,
            'amount_cents'    => (int) $plan->amount_cents,
            'currency'        => (string) $plan->currency,
            'next_payment_at' => $plan->next_payment_at,
            'resume_at'       => $plan->resume_at,
            'notified'        => $notify,
        ], 200);
    }
}
