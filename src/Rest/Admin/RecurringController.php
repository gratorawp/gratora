<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Campaigns\CampaignRepository;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Auth\Capabilities;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\GatewayTransportException;
use Dono\Gateways\PaymentRetryUnavailable;
use Dono\Gateways\SubscriptionAware;
use Dono\Gateways\SubscriptionChangeNeedsApproval;
use Dono\Gateways\SupportsPaymentRetry;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanActions;
use Dono\Recurring\RecurringPlanChange;
use Dono\Recurring\RecurringPlanRepository;
use Dono\Vendor\Queryable\ModelQueryBuilder;
use Dono\Vendor\Queryable\QueryBuilder;
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

    /**
     * flags is LONGTEXT, so a non-JSON value can reach it: MySQL raises on one
     * and MariaDB returns NULL, and the JSON_VALID guard makes both return NULL.
     * The flag is written as a JSON boolean, which unquotes to the string
     * 'true'; '1' covers a truthy value arriving from anywhere else.
     */
    private const FAILURE_FLAG_PREDICATE = "JSON_UNQUOTE(JSON_EXTRACT("
        . "IF(JSON_VALID(flags), flags, NULL), "
        . "'$.subscription_creation_failed')) IN ('true', '1')";

    /**
     * A donation is not stranded while its own flow can still finish: the plan
     * is created in the request that marks it paid, and a redirect-side confirm
     * can land ahead of the webhook that creates it.
     */
    private const SETTLE_MINUTES = 15;

    /**
     * How far back the screen looks. A retry anchors the next charge to the
     * moment it is run, so past a quarter the schedule is a conversation with
     * the donor rather than a click, and the bound keeps the read inside the
     * (recurring_plan_id, paid_at) index range instead of every plan-less row.
     */
    private const WINDOW_DAYS = 90;

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
            'args'                => [
                'include_test' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/recurring/unlinked', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => static fn () => Capabilities::userCan('dono_view_donations'),
            'callback'            => [$this, 'unlinked'],
            'args'                => [
                'limit' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
            ],
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

        // How many plans the current filters would have shown if test ones
        // counted. An org setting recurring up meets it entirely in test mode,
        // and an empty screen reads as a broken integration rather than as a
        // hidden one. Only asked when the caller has not already opted in,
        // because then nothing is hidden and the number would be noise.
        if (! $args['include_test']) {
            $response->header(
                'X-Dono-Test-Hidden',
                (string) max(0, $this->plans->countAdmin(['include_test' => true] + $args) - $total)
            );
        }

        return $response;
    }

    /**
     * The figures above the list. include_test carries the same meaning it has
     * on index(): the caller is looking at test plans, so the totals over that
     * list have to count them too.
     *
     * @since 1.0.0
     */
    public function stats(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(
            $this->plans->recurringStats(gmdate('Y-m-d H:i:s'), (bool) $request['include_test']),
            200
        );
    }

    /**
     * Recurring donations the donor is being charged for on a schedule that was
     * never created at the gateway.
     *
     * @since 1.0.0
     */
    public function unlinked(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request['limit'];
        $rows  = $this->unlinkedQuery()
            ->orderBy('paid_at', 'DESC')
            ->limit($limit)
            ->getAll();

        return new WP_REST_Response([
            // A page shorter than the limit already is the count.
            'total'       => count($rows) < $limit ? count($rows) : $this->countUnlinked(),
            'window_days' => self::WINDOW_DAYS,
            // Creating the plan is a refund-grade action, so a reader with view
            // access has to hand these on rather than act on them.
            'can_retry'   => Capabilities::userCan('dono_refund_donations'),
            'items'       => array_map(static fn (Donation $d): array => [
                'reference'        => (string) $d->reference,
                'amount_cents'     => (int) $d->amount_cents,
                'currency'         => (string) $d->currency,
                'frequency'        => (string) $d->frequency,
                'is_test'          => (bool) $d->is_test,
                // The retry endpoint takes a recorded failure only; the rest
                // need the gateway looked at before anyone acts.
                'failure_recorded' => ! empty(((array) ($d->flags ?? []))['subscription_creation_failed']),
            ], $rows),
        ], 200);
    }

    /** @since 1.0.0 */
    private function countUnlinked(): int
    {
        return (int) $this->unlinkedQuery()->count();
    }

    /**
     * Money collected on a repeating schedule with nothing scheduled to collect
     * it again: the first charge landed and no plan was ever linked to it.
     *
     * Two ways in, because the failure flag is written only where the handler
     * survives long enough to catch its own error. A recorded failure is one. A
     * paid recurring donation on a gateway that runs subscriptions of its own is
     * the other, and it is the one that catches a worker killed mid-flight or a
     * delivery that never arrived. A gateway that schedules nothing is left out:
     * a plan-less donation of its own proves nothing about a schedule.
     *
     * Bounded at both ends: inside SETTLE_MINUTES the donation's own flow may
     * still be running, and past WINDOW_DAYS it is history rather than
     * something a retry recovers.
     *
     * A partial refund did not end the schedule, so it stays in scope; a full
     * refund gave the money back and is not a renewal anyone is waiting on.
     *
     * @since 1.0.0
     */
    private function unlinkedQuery(): ModelQueryBuilder
    {
        $now      = time();
        $gateways = $this->planCreatingGateways();

        // Test donations count here, unlike every money figure on this screen.
        // A recurring donation that produced no plan is a broken integration
        // rather than revenue, and an org finding that out in test mode is the
        // entire point: excluding them hides the fault until it happens for
        // real.
        $settled = gmdate('Y-m-d H:i:s', $now - (self::SETTLE_MINUTES * MINUTE_IN_SECONDS));

        return Donation::query()
            ->where('kind', 'donation')
            ->whereNull('recurring_plan_id')
            ->where('frequency', 'one_time', '<>')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('paid_at', gmdate('Y-m-d H:i:s', $now - (self::WINDOW_DAYS * DAY_IN_SECONDS)), '>=')
            ->where(static function (QueryBuilder $q) use ($gateways, $settled): void {
                // First condition of the group: whereRaw carries no AND.
                //
                // A recorded failure is reported the moment it happens. The
                // settling delay below exists because a donation with no plan
                // may simply not have finished yet, and a donation that already
                // says why it failed is not waiting on anything.
                $q->whereRaw(self::FAILURE_FLAG_PREDICATE);

                if ($gateways !== []) {
                    $q->orWhere(static function (QueryBuilder $inner) use ($gateways, $settled): void {
                        $inner->whereIn('gateway', $gateways)
                            ->where('paid_at', $settled, '<=');
                    });
                }
            });
    }

    /**
     * Gateways that create a subscription of their own, so a paid recurring
     * donation of theirs with no plan row means nothing is scheduled.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    private function planCreatingGateways(): array
    {
        $ids = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if ($gateway instanceof SubscriptionAware) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
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
        } catch (GatewayTransportException $e) {
            // The request never left this server, so nothing at the gateway was
            // attempted, let alone refused. Reporting it as a rejected change
            // sends an admin to look at the plan, the card and the gateway
            // dashboard, none of which are involved.
            return new WP_Error(
                'dono_gateway_unreachable',
                sprintf(
                    /* translators: %s: transport error, e.g. a DNS failure */
                    __('This site could not reach the payment provider, so nothing has changed: %s. That is a problem with this server rather than with the plan. Try again in a moment.', 'dono'),
                    $e->getMessage()
                ),
                ['status' => 503]
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
