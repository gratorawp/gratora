<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Rest\Paging;
use Dono\Foundation\Auth\Capabilities;

use Dono\Campaigns\Campaign;
use Dono\Currency\Currency;
use Dono\Donations\ChannelClassifier;
use Dono\Donations\Donation;
use Dono\Donations\DonationQueries;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationNoteRepository;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\Refund;
use Dono\Gateways\PayPal\PayPalHoldReason;
use Dono\Donors\Donor;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Foundation\Helpers\Csv;
use Dono\Currency\SupportedCurrencies;
use Dono\Foundation\Helpers\Money;
use Dono\Forms\Blocks\CustomFieldLabels;
use Dono\Forms\Form;
use Dono\Funds\Fund;
use Dono\Receipts\Receipt;
use Dono\Receipts\ReceiptIssuer;
use Dono\Receipts\ReceiptRepository;
use Dono\Settings\SettingsService;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin donation endpoints: list, detail, refund, resend-receipt, CSV export.
 * Responses include decrypted donor PII; capability-gated.
 *
 * @since 1.0.0
 */
final class DonationsController
{
    private const NAMESPACE = 'dono/v1';

    private const EXPORT_PAGE     = 1000;
    private const EXPORT_MAX_ROWS = 50000;

    /** @since 1.0.0 */
    public function __construct(
        private DonationRepository $donations,
        private DonorRepository $donors,
        private DonorService $donorService,
        private DonationService $donationService,
        private ReceiptRepository $receipts,
        private ReceiptIssuer $receiptIssuer,
        private DonationNoteRepository $notes,
        private \Dono\Receipts\Renderers\GenericReceiptRenderer $genericRenderer,
        private \Dono\Gateways\GatewayManager $gateways,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/donations', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => $this->indexArgs(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'record'],
                // Not dono_edit_donations: that cap is for notes, and this
                // creates confirmed money at a caller-chosen amount and date.
                // Marking an existing pending donation paid already requires
                // this one, and creating an already-paid donation cannot need
                // less than that.
                'permission_callback' => static fn () => Capabilities::userCan('dono_refund_donations'),
                'args'                => $this->recordArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/export\.csv', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'exportCsv'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => $this->indexArgs(),
        ]);

        // Campaign names for the record-a-donation picker.
        //
        // /admin/campaigns needs dono_manage_campaigns, which is exactly what a
        // bookkeeper role created to enter checks will not have. The donations
        // list already shows campaign titles to anyone who can read it, so
        // serving the names under the same capability discloses nothing new,
        // and it does not widen the campaign-management surface.
        register_rest_route(self::NAMESPACE, '/admin/donations/campaign-options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'campaignOptions'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        // Fund names for the record-a-donation picker, under the donations
        // capability for the same reason campaign-options exists: /admin/funds
        // needs dono_manage_campaigns, which the bookkeeper entering the
        // envelope does not have.
        register_rest_route(self::NAMESPACE, '/admin/donations/fund-options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'fundOptions'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        // Before the (?P<reference>...) route, which would otherwise swallow it.
        register_rest_route(self::NAMESPACE, '/admin/donations/gateway-options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'gatewayOptions'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'include_test' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'stats'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => $this->indexArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'show'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)/refund', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'refund'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_refund_donations'),
            'args'                => [
                'amount_cents' => ['type' => 'integer', 'minimum' => 1],
                'reason'       => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)/mark-paid', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'markPaid'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_refund_donations'),
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)/mark-failed', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'markFailed'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_refund_donations'),
            'args'                => [
                'reason' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)/resend-receipt', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'resendReceipt'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_resend_receipt'),
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)/retry-subscription', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'retrySubscription'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_refund_donations'),
        ]);

        register_rest_route(self::NAMESPACE, '/admin/receipts/(?P<receipt_id>\d+)/pdf', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'downloadReceipt'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'receipt_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/receipts/preview', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'previewReceipt'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/(?P<reference>[A-Za-z0-9_\-]+)/notes', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createNote'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_edit_donations'),
            'args'                => [
                'body' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donations/notes/(?P<note_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'deleteNote'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_edit_donations'),
            'args'                => [
                'note_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function createNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }
        $params = $request->get_json_params() ?: $request->get_body_params();
        $body   = trim((string) ($params['body'] ?? ''));
        if ($body === '') {
            return new WP_Error('dono_invalid', __('Note body is required.', 'dono'), ['status' => 400]);
        }
        $note = $this->notes->create($donation->id, $body, get_current_user_id() ?: null);
        return new WP_REST_Response($note, 201);
    }

    /** @since 1.0.0 */
    public function deleteNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $noteId = (int) $request['note_id'];
        $note = $this->notes->findById($noteId);
        if (! $note) {
            return new WP_Error('dono_not_found', __('Note not found.', 'dono'), ['status' => 404]);
        }
        if ($note->author_user_id && $note->author_user_id !== get_current_user_id() && ! current_user_can('manage_options')) {
            return new WP_Error('dono_forbidden', __('You cannot delete this note.', 'dono'), ['status' => 403]);
        }
        $this->notes->delete($noteId);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_view_donations');
    }

    /** @since 1.0.0 */
    public function fundOptions(): WP_REST_Response
    {
        $rows = Fund::query()
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->limit(500)
            ->getAll();

        return new WP_REST_Response(array_map(
            static fn (Fund $f): array => [
                'id'         => (int) $f->id,
                'name'       => (string) $f->name,
                'is_default' => (bool) $f->is_default,
            ],
            $rows,
        ), 200);
    }

    /**
     * Id and title only, for the picker. Archived campaigns are included: a
     * check that arrived during last year's appeal belongs to last year's
     * appeal, and by the time someone is entering it the appeal is usually over.
     *
     * @since 1.0.0
     */
    public function campaignOptions(): WP_REST_Response
    {
        $rows = Campaign::query()
            ->whereIn('status', ['published', 'archived'])
            ->orderBy('created_at', 'DESC')
            ->limit(500)
            ->getAll();

        return new WP_REST_Response(array_map(
            static fn (Campaign $c): array => [
                'id'       => (int) $c->id,
                'title'    => (string) $c->title,
                'archived' => (string) $c->status === 'archived',
            ],
            $rows,
        ), 200);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function recordArgs(): array
    {
        return [
            'email'          => ['type' => 'string', 'required' => true, 'format' => 'email'],
            'first_name'     => ['type' => 'string'],
            'last_name'      => ['type' => 'string'],
            'amount_cents'   => ['type' => 'integer', 'required' => true, 'minimum' => 1],
            'currency'       => ['type' => 'string', 'pattern' => '^[A-Za-z]{3}$'],
            'payment_method' => ['type' => 'string', 'required' => true],
            'received_at'    => ['type' => 'string', 'required' => true],
            'campaign_id'    => ['type' => ['integer', 'null']],
            'fund_id'        => ['type' => ['integer', 'null']],
            'note_to_org'    => ['type' => 'string'],
            'send_receipt'   => ['type' => 'boolean', 'default' => false],
            // The admin's answer to a 409: yes, I know, record it anyway.
            'confirm_duplicate' => ['type' => 'boolean', 'default' => false],
        ];
    }

    /**
     * Record money that arrived off the site: a check, cash in a bucket, a
     * bank transfer nobody told the site about.
     *
     * It runs the same createPending + confirm path a donated donation runs,
     * so aggregates, donor counters and every downstream listener behave
     * identically. Three things differ, and each of them is a bug if left out:
     * the money is dated when it arrived rather than when it was typed in, the
     * donor is not emailed instructions to pay something already paid, and the
     * row is never flagged as a test even on a site left in test mode.
     *
     * @since 1.0.0
     */
    public function record(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $offline = $this->gateways->get('offline');
        if (! $offline) {
            return new WP_Error('dono_offline_unavailable', __('The offline gateway is not available.', 'dono'), ['status' => 500]);
        }

        $method = (string) $request['payment_method'];
        if (! in_array($method, $offline->paymentMethods(), true)) {
            return new WP_Error(
                'dono_invalid_payment_method',
                __('That is not a way money can arrive offline.', 'dono'),
                ['status' => 400]
            );
        }

        $receivedAt = $this->receivedAt((string) $request['received_at']);
        if ($receivedAt === null) {
            return new WP_Error(
                'dono_invalid_received_at',
                __('Give the date the money arrived, and it cannot be in the future.', 'dono'),
                ['status' => 400]
            );
        }

        $currency = strtoupper((string) ($request['currency'] ?: Money::defaultCurrency()));

        // An offline gift in a currency with no configured rate is recorded and
        // then sits outside every total, which is not something to accept by
        // typo. Storage is always major x 100, so an amount that does not land
        // on a whole major unit in a zero-decimal currency rounds at the gateway
        // and mischarges.
        if (Currency::minorUnits($currency) === 0 && ((int) $request['amount_cents']) % 100 !== 0) {
            return new WP_Error(
                'dono_invalid_amount',
                __('This currency does not support fractional amounts.', 'dono'),
                ['status' => 422]
            );
        }

        if (! SupportedCurrencies::accepts($currency)) {
            return new WP_Error(
                'dono_unsupported_currency',
                sprintf(
                    /* translators: 1: the currency code entered, 2: the accepted codes. */
                    __('%1$s is not one of your accepted currencies (%2$s). Add it under Settings, Currency, so it can be converted into your reporting totals.', 'dono'),
                    $currency,
                    implode(', ', SupportedCurrencies::all())
                ),
                ['status' => 422]
            );
        }

        if (! (bool) $request['confirm_duplicate']) {
            $existing = $this->donationLike(
                (string) $request['email'],
                (int) $request['amount_cents'],
                $currency,
                $receivedAt
            );
            if ($existing !== null) {
                return new WP_Error(
                    'dono_duplicate_donation',
                    __('This donor is already down for the same amount on that date.', 'dono'),
                    ['status' => 409, 'reference' => (string) $existing->reference]
                );
            }
        }

        $intent = new DonationIntent(
            email: (string) $request['email'],
            amount_cents: (int) $request['amount_cents'],
            currency: $currency,
            gateway: 'offline',
            campaign_id: $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
            fund_id: $request['fund_id'] !== null ? (int) $request['fund_id'] : null,
            profile: array_filter([
                'first_name' => (string) $request['first_name'],
                'last_name'  => (string) $request['last_name'],
            ]),
            payment_method: $method,
            // The marker that keeps this out of the `direct` bucket and out of
            // the offline instructions email. ChannelClassifier maps it.
            source_attribution: ['utm_source' => 'admin', 'utm_medium' => ChannelClassifier::MANUAL],
            note_to_org: (string) $request['note_to_org'] ?: null,
            // A real check is real money even on a site left rehearsing. This
            // has to be settled before the insert rather than corrected after
            // it: Gift Aid reads the flag on dono.donation.creating to decide
            // whether to write a claim snapshot, and it never asks again, so a
            // donation corrected a moment later still loses the 25%.
            is_test: false,
            // Someone exercised their right to erasure. An admin typing their
            // email is not them coming back, so the money is recorded against
            // the erased shell and the erasure holds.
            reactivate_redacted_donor: false,
        );

        $donation = null;

        try {
            $created  = $this->donationService->createPending($intent);
            $donation = $created['donation'];

            // Backdate the row so it lists and filters in the period the money
            // actually arrived in.
            Donation::query()->where('id', (int) $donation->id)->update([
                'created_at' => $receivedAt,
            ]);
            $donation->created_at = $receivedAt;

            $suppress = ! (bool) $request['send_receipt'];
            $filter   = static function (bool $should, Donation $candidate) use ($donation, $suppress): bool {
                return (int) $candidate->id === (int) $donation->id ? ! $suppress && $should : $should;
            };
            add_filter('dono.receipt.should_issue', $filter, 10, 2);

            try {
                $donation = $this->donationService->confirm($donation, [
                    'gateway_txn_id' => 'manual-' . wp_generate_password(12, false),
                    'payment_method' => $method,
                    'paid_at'        => $receivedAt,
                ]);
            } finally {
                remove_filter('dono.receipt.should_issue', $filter, 10);
            }
        } catch (\Throwable $e) {
            // Throwable, not RuntimeException: anything else escapes as a PHP
            // fatal, leaving the admin a blank 500 with no JSON and no way to
            // tell whether the money was recorded.
            //
            // What state the row is actually in decides the answer, so read it
            // rather than assume the throw means nothing happened.
            $recorded = $donation === null
                ? null
                : Donation::query()->find('id', (int) $donation->id);

            // The money is on the books and something after it failed: a
            // listener on the completed event, or the note below. Reporting
            // failure here is a lie that invites the admin to enter the same
            // check a second time.
            if ($recorded !== null && (string) $recorded->status === 'paid') {
                return new WP_REST_Response([
                    'reference' => $recorded->reference,
                    'status'    => $recorded->status,
                    'paid_at'   => $recorded->paid_at,
                ], 201);
            }

            // createPending commits its own transaction, so a failure inside
            // confirm() leaves a pending row dated to the check, which reads on
            // the list as money the org is still waiting for and is never
            // reconciled because nobody knows it is there. Marked failed it says
            // what happened, and unlike deleting it does not orphan rows an
            // add-on wrote against this donation on dono.donation.creating.
            if ($recorded !== null && (string) $recorded->status === 'pending') {
                $this->donationService->markFailed(
                    $recorded,
                    __('Recording this donation by hand did not finish.', 'dono')
                );
            }

            return new WP_Error('dono_record_failed', $e->getMessage(), ['status' => 500]);
        }

        $this->notes->create(
            (int) $donation->id,
            sprintf(
                /* translators: %s: how the money arrived, e.g. check. */
                __('Recorded by hand. Received as %s.', 'dono'),
                $method
            ),
            get_current_user_id() ?: null
        );

        return new WP_REST_Response([
            'reference' => $donation->reference,
            'status'    => $donation->status,
            'paid_at'   => $donation->paid_at,
        ], 201);
    }

    /**
     * A donation already on the books that this one would be a second copy of.
     *
     * Two checks for the same amount from the same donor on the same day are
     * genuinely possible, so this cannot silently dedupe: swallowing a real
     * second gift is worse than the double-entry it would prevent. It warns,
     * and the admin decides. What it catches is the timed-out request retried,
     * the second admin working the same envelope, and the check recorded by
     * hand that the donor had in fact already paid online.
     *
     * @since 1.0.0
     */
    /**
     * Why the money has not landed yet, in words.
     *
     * A donation can sit at processing for days, and the gateway is the only
     * thing that knows whether that is normal. Without this the screen says
     * "Processing" and stops, which is indistinguishable from broken.
     *
     * @since 1.0.0
     */
    private static function processingReason(Donation $donation): ?string
    {
        if ($donation->status !== 'processing' || $donation->gateway !== 'paypal') {
            return null;
        }

        $meta = (array) ($donation->gateway_metadata ?? []);
        if (! array_key_exists('paypal_pending_reason', $meta)) {
            return null;
        }

        return PayPalHoldReason::describe((string) $meta['paypal_pending_reason']);
    }

    private function donationLike(string $email, int $amountCents, string $currency, string $receivedAt): ?Donation
    {
        $donor = $this->donorService->findByEmail($email);
        if (! $donor) {
            return null;
        }

        $day = substr($receivedAt, 0, 10);

        $matches = Donation::query()
            ->where('donor_id', (int) $donor->id)
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('kind', 'donation')
            ->where('amount_cents', $amountCents)
            ->where('currency', $currency)
            ->where('paid_at', $day . ' 00:00:00', '>=')
            ->where('paid_at', $day . ' 23:59:59', '<=')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->getAll();

        return $matches[0] ?? null;
    }

    /**
     * Noon rather than midnight: a date-only value rendered in a timezone
     * behind the site would otherwise slide to the previous day.
     *
     * @since 1.0.0
     */
    private function receivedAt(string $raw): ?string
    {
        $raw  = trim($raw);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        // createFromFormat is lenient: it rolls 2026-02-30 forward to March
        // rather than rejecting it, so compare the parse back against what was
        // typed. A date that does not exist is a typo, not a date.
        if (! $date || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        // A calendar day ahead of the site, not of UTC. WordPress pins PHP to
        // UTC, so an admin anywhere east of the site sees a local date the
        // server would otherwise call the future, and could not record today's
        // cash at all. One day of slack covers every offset in use.
        $latest = (new \DateTimeImmutable(current_time('Y-m-d')))->modify('+1 day');
        if ($date > $latest) {
            return null;
        }

        // Nothing before Dono existed. Catches a mistyped year landing in the
        // earliest bucket of every time series, where it is invisible.
        if ($date->format('Y') < '2000') {
            return null;
        }

        return $date->setTime(12, 0)->format('Y-m-d H:i:s');
    }

    /** @since 1.0.0 */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $search  = $request['search']   !== null ? trim((string) $request['search']) : '';
        $donorId = $request['donor_id'] !== null ? (int) $request['donor_id'] : 0;

        // donor_id hard-scopes to one donor; search then narrows by reference only.
        $matchingDonorIds = $donorId > 0
            ? []
            : ($search !== '' ? $this->donorService->findIdsBySearch($search) : []);

        $result = $this->donations->listAdmin([
            'page'               => Paging::page($request['page'] ?? null),
            'per_page'           => (int) ($request['per_page'] ?? 25),
            'orderby'            => (string) ($request['orderby'] ?? 'created_at'),
            'order'              => (string) ($request['order']   ?? 'desc'),
            'status'             => $request['status'] !== null ? (string) $request['status'] : null,
            'search'             => $search !== '' ? $search : null,
            'matching_donor_ids' => $matchingDonorIds,
            'donor_id'           => $donorId,
            'campaign_id'        => $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
            'form_id'            => $request['form_id']     !== null ? (int) $request['form_id']     : null,
            'gateway'            => $request['gateway']     !== null ? (string) $request['gateway'] : null,
            'frequency'          => $request['frequency']   !== null ? (string) $request['frequency'] : null,
            'is_test'            => $request['is_test']     !== null ? (bool) $request['is_test']    : null,
            'include_test'       => (bool) $request['include_test'],
            'created_from'       => $request['created_from'] !== null ? (string) $request['created_from'] : null,
            'created_to'         => $request['created_to']   !== null ? (string) $request['created_to']   : null,
        ]);

        // Batch-load donor/campaign/form rows so the page render stays O(1)
        // round-trips per related table instead of N+1 per donation.
        $donorIds    = array_unique(array_filter(array_map(fn ($d) => (int) $d->donor_id,    $result['items'])));
        $campaignIds = array_unique(array_filter(array_map(fn ($d) => (int) $d->campaign_id, $result['items'])));
        $formIds     = array_unique(array_filter(array_map(fn ($d) => (int) $d->form_id,     $result['items'])));
        $fundIds     = array_unique(array_filter(array_map(fn ($d) => (int) $d->fund_id,     $result['items'])));

        $donorsById = $this->donors->findManyByIds(array_values($donorIds));
        $campaignsById = [];
        if ($campaignIds !== []) {
            foreach (Campaign::query()->whereIn('id', array_values($campaignIds))->getAll() as $c) {
                $campaignsById[(int) $c->id] = $c;
            }
        }
        $formsById = [];
        if ($formIds !== []) {
            foreach (Form::query()->whereIn('id', array_values($formIds))->getAll() as $f) {
                $formsById[(int) $f->id] = $f;
            }
        }
        $fundsById = [];
        if ($fundIds !== []) {
            foreach (Fund::query()->whereIn('id', array_values($fundIds))->getAll() as $f) {
                $fundsById[(int) $f->id] = $f;
            }
        }

        $shaped = [];
        foreach ($result['items'] as $d) {
            /** @var Donation $d */
            $shaped[] = $this->shapeDonation(
                $d,
                $donorsById[$d->donor_id]    ?? null,
                $d->campaign_id ? ($campaignsById[$d->campaign_id] ?? null) : null,
                $d->form_id     ? ($formsById[$d->form_id]         ?? null) : null,
                $d->fund_id     ? ($fundsById[$d->fund_id]         ?? null) : null,
            );
        }

        $perPage = (int) ($request['per_page'] ?? 25);
        $response = new WP_REST_Response($shaped, 200);
        $response->header('X-WP-Total',      (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));

        // Only when the caller did not ask about test rows: otherwise they are
        // already looking at exactly what they chose. Nothing is hidden once
        // include_test is on either, so the count would just be noise.
        if ($request['is_test'] === null && ! $request['include_test']) {
            $response->header('X-Dono-Test-Hidden', (string) $this->donations->countTestHidden([
                'status'             => $request['status'] !== null ? (string) $request['status'] : null,
                'search'             => $search !== '' ? $search : null,
                'matching_donor_ids' => $matchingDonorIds,
                'donor_id'           => $donorId,
                'campaign_id'        => $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
                'form_id'            => $request['form_id']     !== null ? (int) $request['form_id']     : null,
                'gateway'            => $request['gateway']     !== null ? (string) $request['gateway'] : null,
                'frequency'          => $request['frequency']   !== null ? (string) $request['frequency'] : null,
                'created_from'       => $request['created_from'] !== null ? (string) $request['created_from'] : null,
                'created_to'         => $request['created_to']   !== null ? (string) $request['created_to']   : null,
            ]));
        }

        return $response;
    }

    /**
     * Aggregate KPIs for the donations list. Takes the same args as index(),
     * `include_test` among them, so "raised" and "donors" track whatever the
     * user is currently viewing. The response carries `includes_test` for the
     * screen to label the figures with.
     *
     * @since 1.0.0
     */
    public function stats(WP_REST_Request $request): WP_REST_Response
    {
        $search  = $request['search']   !== null ? trim((string) $request['search']) : '';
        $donorId = $request['donor_id'] !== null ? (int) $request['donor_id'] : 0;

        $matchingDonorIds = $donorId > 0
            ? []
            : ($search !== '' ? $this->donorService->findIdsBySearch($search) : []);

        $stats = $this->donations->aggregateAdmin([
            'status'             => $request['status'] !== null ? (string) $request['status'] : null,
            'search'             => $search !== '' ? $search : null,
            'matching_donor_ids' => $matchingDonorIds,
            'donor_id'           => $donorId,
            'campaign_id'        => $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
            'form_id'            => $request['form_id']     !== null ? (int) $request['form_id']     : null,
            'gateway'            => $request['gateway']     !== null ? (string) $request['gateway'] : null,
            'frequency'          => $request['frequency']   !== null ? (string) $request['frequency'] : null,
            'is_test'            => $request['is_test']     !== null ? (bool) $request['is_test']    : null,
            'include_test'       => (bool) $request['include_test'],
            'created_from'       => $request['created_from'] !== null ? (string) $request['created_from'] : null,
            'created_to'         => $request['created_to']   !== null ? (string) $request['created_to']   : null,
        ]);

        return new WP_REST_Response($stats, 200);
    }

    /** @since 1.0.0 */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }

        $donor = $this->donors->findById($donation->donor_id);

        $receipts = array_map(
            fn (Receipt $r): array => [
                'id'              => $r->id,
                'renderer_id'     => $r->renderer_id,
                'receipt_number'  => $r->receipt_number,
                'country'         => $r->country,
                'locale'          => $r->locale,
                'sent_to_email_at'=> $r->sent_to_email_at,
                'voided'          => (bool) $r->voided,
                'voided_at'       => $r->voided_at,
                'issued_at'       => $r->issued_at,
            ],
            $this->receipts->forDonation($donation->id),
        );

        $refundRows = Refund::query()
            ->where('donation_id', $donation->id)
            ->orderBy('id', 'ASC')
            ->getAll();
        $refunds = array_map(
            fn (Refund $r): array => [
                'id'                => $r->id,
                'amount_cents'      => $r->amount_cents,
                'currency'          => $r->currency,
                'reason'            => $r->reason,
                'initiated_by'      => $r->initiated_by,
                'initiated_user_id' => $r->initiated_user_id,
                'gateway_refund_id' => $r->gateway_refund_id,
                'status'            => $r->status,
                'occurred_at'       => $r->occurred_at,
            ],
            $refundRows,
        );

        $refundedTotal = array_sum(array_map(
            fn ($r) => $r['status'] === 'succeeded' ? (int) $r['amount_cents'] : 0,
            $refunds,
        ));

        // Channel classification from the donation's UTM attribution.
        $attr = is_array($donation->source_attribution) ? $donation->source_attribution : [];
        $channel = ChannelClassifier::classify($attr);

        // Campaign + form labels (display-only lookups, cheap).
        $campaign = $donation->campaign_id ? Campaign::query()->find('id', $donation->campaign_id) : null;
        $form     = $donation->form_id ? Form::query()->find('id', $donation->form_id) : null;
        $fund     = $donation->fund_id ? Fund::query()->find('id', (int) $donation->fund_id) : null;

        // 5 most recent other donations from this donor, sidebar context.
        $related = $donor
            ? array_map(
                fn (Donation $d): array => [
                    'id'           => (int) $d->id,
                    'reference'    => $d->reference,
                    'amount_cents' => (int) $d->amount_cents,
                    'currency'     => (string) $d->currency,
                    'status'       => (string) $d->status,
                    'campaign_id'  => $d->campaign_id !== null ? (int) $d->campaign_id : null,
                    'paid_at'      => $d->paid_at,
                    'created_at'   => (string) $d->created_at,
                    'is_self'      => $d->id === $donation->id,
                ],
                Donation::query()
                    ->where('donor_id', $donor->id)
                    ->orderBy('created_at', 'DESC')
                    ->limit(5)
                    ->getAll(),
            )
            : [];

        // Donor lifetime block (pre-aggregated on the donor row).
        $donorBlock = null;
        if ($donor) {
            // Contact details are the donor record, not the donation record, so
            // they follow dono_view_donors the way the CSV columns do.
            $withDonorPii = $donor->redacted_at === null && $this->canReadDonorPii();

            $donorBlock = [
                'id'                  => (int) $donor->id,
                'name'                => $this->donorName($donor),
                // Explicit, so the UI never has to infer erasure from a nulled
                // email: nothing can be emailed to a donor who has been erased.
                'redacted'            => $donor->redacted_at !== null,
                'email'               => $withDonorPii ? $this->donorService->decryptEmail($donor) : null,
                'phone'               => $withDonorPii ? $this->donorService->decryptPhone($donor) : null,
                'address'             => $withDonorPii ? $this->donorService->decryptAddress($donor) : null,
                'country'             => $donor->country,
                'donor_type'          => $donor->donor_type,
                'first_donation_at'   => $donor->first_donation_at,
                'last_donation_at'    => $donor->last_donation_at,
                'lifetime' => [
                    'count'        => (int) $donor->donations_count,
                    'total_cents'  => (int) $donor->total_donated_cents,
                    // total_donated_cents is the org base-currency aggregate,
                    // so it must be labeled with the base currency, not this
                    // donation's currency, which may be foreign.
                    'currency'     => $this->baseCurrency(),
                ],
            ];
        }

        return new WP_REST_Response([
            'donation' => $this->shapeDonation($donation, $donor, $campaign, $form, $fund) + [
                // Fields the list endpoint doesn't include.
                'fee_cents'            => $donation->fee_cents,
                'net_cents'            => $donation->net_cents,
                'gateway_intent_id'    => $donation->gateway_intent_id,
                'gateway_txn_id'       => $donation->gateway_txn_id,
                'payment_method'       => $donation->payment_method,
                'payment_method_brand' => $donation->payment_method_brand,
                'payment_method_last4' => $donation->payment_method_last4,
                'note_to_org'          => $donation->note_to_org,
                'custom_data'          => $this->donationService->decryptCustomData($donation),
                'custom_field_labels'  => $form ? CustomFieldLabels::forBlocks((string) $form->blocks) : [],
                'donor_name_given'     => trim((string) $donation->donor_first_name . ' ' . (string) $donation->donor_last_name) ?: null,
                'is_anonymous'         => $donation->is_anonymous,
                'failure_reason'       => $donation->failure_reason,
                'processing_reason'    => self::processingReason($donation),
                'source_attribution'   => $donation->source_attribution,
                'channel'              => $channel,
                'flags'                => $donation->flags,
                'refunded_at'          => $donation->refunded_at,
                'refunded_cents'       => $refundedTotal,
                'refundable_cents'     => max(0, $donation->amount_cents - $refundedTotal),
                'recurring_plan_id'    => $donation->recurring_plan_id,
                'frequency'            => $donation->frequency,
                // Slugs only. shapeDonation already carries id and title for
                // all three, and array union keeps the LEFT value, so restating
                // them here would silently throw the richer version away.
                'campaign_slug'        => $campaign ? (string) $campaign->slug : null,
                'form_slug'            => $form ? (string) $form->slug : null,
            ],
            'donor'    => $donorBlock,
            'receipts' => $receipts,
            'refunds'  => $refunds,
            'related'  => $related,
            'notes'    => $this->notes->listForDonation($donation->id),
        ], 200);
    }

    /** @since 1.0.0 */
    public function markPaid(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }
        if ($donation->status === 'paid') {
            return new WP_REST_Response($this->show($request)->get_data(), 200);
        }
        // `processing` is here because a bank debit settles days after it was
        // authorised, and an admin reconciling a bank statement is often the
        // first to know it landed.
        if (! in_array($donation->status, ['pending', 'processing', 'failed'], true)) {
            return new WP_Error(
                'dono_invalid_transition',
                sprintf(
                    /* translators: %s: current donation status. */
                    __('Cannot mark a %s donation as paid.', 'dono'),
                    $donation->status
                ),
                ['status' => 422]
            );
        }
        // Only an offline donation gets an offline marker. A gateway donation
        // confirmed by hand, a webhook that never arrived or a card the admin
        // watched clear in the processor's dashboard, still moved its money
        // through that gateway. Stamping `offline` on it would fabricate a
        // transaction id and record a card payment as offline, so anyone
        // reconciling the site against a Stripe payout would find no such id.
        // confirm() falls back to the row's own values, so an empty array here
        // keeps whatever the gateway already recorded.
        $confirmation = [];
        if ($donation->gateway === 'offline') {
            $confirmation = [
                'gateway_txn_id' => 'offline-' . wp_generate_password(12, false),
                'payment_method' => 'offline',
            ];
        } elseif ((string) ($donation->gateway_txn_id ?? '') === '') {
            // No settlement id without the webhook; the intent is the honest
            // identifier, and it is what you search for at the processor.
            $confirmation = ['gateway_txn_id' => (string) $donation->gateway_intent_id];
        }

        try {
            $this->donationService->confirm($donation, $confirmation);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_confirm_failed', $e->getMessage(), ['status' => 500]);
        }
        return $this->show($request);
    }

    /** @since 1.0.0 */
    public function markFailed(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }
        if ($donation->status === 'failed') {
            return new WP_REST_Response($this->show($request)->get_data(), 200);
        }
        if ($donation->status === 'paid') {
            return new WP_Error(
                'dono_invalid_transition',
                __('A paid donation cannot be marked as failed. Use refund instead.', 'dono'),
                ['status' => 422]
            );
        }
        // A submitted bank debit can bounce, and the admin may hear about it
        // from the bank before any webhook arrives.
        if (! in_array($donation->status, ['pending', 'processing'], true)) {
            return new WP_Error(
                'dono_invalid_transition',
                sprintf(
                    /* translators: %s: current donation status. */
                    __('Cannot mark a %s donation as failed.', 'dono'),
                    $donation->status
                ),
                ['status' => 422]
            );
        }
        $body   = (array) ($request->get_json_params() ?? []);
        $reason = isset($body['reason']) && $body['reason'] !== '' ? (string) $body['reason'] : null;
        $this->donationService->markFailed($donation, $reason);
        return $this->show($request);
    }

    /** @since 1.0.0 */
    public function refund(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }

        $body = (array) ($request->get_json_params() ?? []);
        $amount = isset($body['amount_cents']) ? (int) $body['amount_cents'] : (int) $donation->amount_cents;
        $reason = isset($body['reason']) && $body['reason'] !== '' ? (string) $body['reason'] : null;

        try {
            $refund = $this->donationService->refund(
                $donation,
                $amount,
                $reason,
                get_current_user_id() ?: null,
                'admin',
            );
        } catch (RuntimeException $e) {
            return new WP_Error('dono_refund_failed', $e->getMessage(), ['status' => 422]);
        }

        $reloaded = $this->donations->findByReference($reference);

        return new WP_REST_Response([
            'refund' => [
                'id'                => $refund->id,
                'amount_cents'      => $refund->amount_cents,
                'currency'          => $refund->currency,
                'reason'            => $refund->reason,
                'gateway_refund_id' => $refund->gateway_refund_id,
                'status'            => $refund->status,
                'occurred_at'       => $refund->occurred_at,
            ],
            'donation_status' => $reloaded?->status,
            'refunded_at'     => $reloaded?->refunded_at,
        ], 200);
    }

    /** @since 1.0.0 */
    public function resendReceipt(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }

        // Erasure wiped the address, so the issuer would find nothing to send to
        // and return quietly. Refuse instead of reporting a receipt on its way,
        // and leave the existing sent_to_email_at alone: it records a send that
        // really happened, and re-queueing would clear it for a send that cannot.
        $donor = $donation->donor_id ? $this->donors->findById((int) $donation->donor_id) : null;
        if ($donor && $donor->redacted_at !== null) {
            return new WP_Error(
                'dono_donor_redacted',
                __('This donor has been erased, so there is no address to send a receipt to.', 'dono'),
                ['status' => 422],
            );
        }

        $ok = $this->receiptIssuer->requeueForDonation($donation->id);
        if (! $ok) {
            return new WP_Error(
                'dono_resend_unavailable',
                __('Receipts can only be resent for paid donations.', 'dono'),
                ['status' => 422],
            );
        }

        return new WP_REST_Response([
            'queued'    => true,
            'reference' => $reference,
        ], 202);
    }

    /**
     * Retry the PaymentIntent → Subscription conversion for a recurring
     * donation whose first charge succeeded but whose subscription creation
     * failed (Stripe Customer/Price/Subscription chain). Re-reads the PI from
     * Stripe and re-runs the chain; clears the failure flags on success.
     *
     * @since 1.0.0
     */
    public function retrySubscription(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference = (string) $request['reference'];
        $donation  = $this->donations->findByReference($reference);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }

        $flags = (array) ($donation->flags ?? []);
        if (empty($flags['subscription_creation_failed'])) {
            return new WP_Error(
                'dono_no_retry_needed',
                __('No subscription-creation failure is recorded for this donation.', 'dono'),
                ['status' => 422]
            );
        }

        // The same set the unlinked-donation list counts and the one a reversal
        // is allowed to act on: money the organisation still holds is the only
        // money that bought a first period and is owed the ones after it.
        if (! in_array((string) $donation->status, ['paid', 'partial_refund'], true)) {
            return new WP_Error(
                'dono_retry_not_allowed',
                __('A recurring plan can only be created from a donation the organisation was paid and still holds. This one was refunded, reversed, or never settled.', 'dono'),
                ['status' => 422]
            );
        }

        $gateway = $this->gateways->get((string) $donation->gateway);
        if (! $gateway instanceof \Dono\Gateways\Stripe\StripeGateway) {
            return new WP_Error(
                'dono_unsupported_gateway',
                __('Only Stripe subscriptions can be retried.', 'dono'),
                ['status' => 422]
            );
        }

        try {
            $plan = $gateway->retrySubscriptionCreation($donation);
        } catch (RuntimeException $e) {
            return new WP_Error(
                'dono_retry_failed',
                $e->getMessage(),
                ['status' => 502]
            );
        }

        return new WP_REST_Response([
            'retried'   => true,
            'reference' => $reference,
            'plan_id'   => (int) $plan->id,
            'gateway_subscription_id' => (string) $plan->gateway_subscription_id,
        ], 200);
    }

    /** @since 1.0.0 */
    public function downloadReceipt(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $receiptId = (int) $request['receipt_id'];

        $receipt = $this->receipts->findById($receiptId);
        if (! $receipt) {
            return new WP_Error('dono_not_found', __('Receipt not found.', 'dono'), ['status' => 404]);
        }

        $pdf = $this->receiptIssuer->renderReceiptPdf($receiptId);
        if ($pdf === null || $pdf === '') {
            return new WP_Error(
                'dono_render_failed',
                __('Could not regenerate the receipt PDF. The original renderer may have been removed.', 'dono'),
                ['status' => 500],
            );
        }

        // PDF download via rest_pre_serve_request to bypass the JSON serializer.
        $filename = sprintf('%s.pdf', preg_replace('/[^A-Za-z0-9_-]+/', '_', $receipt->receipt_number) ?: 'receipt');
        $route = $request->get_route();

        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $pdf, $filename) {
            if ((string) $req->get_route() !== $route) return $served;

            $server->send_header('Content-Type', 'application/pdf');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Content-Length', (string) strlen($pdf));
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

    /**
     * Renders the GenericReceiptRenderer against an in-memory stub Donation
     * and Donor so the admin can preview the template (with their current
     * settings + logo + merge-tag expansions) without sending a real receipt.
     * Returned inline so the browser displays the PDF.
     *
     * @since 1.0.0
     */
    public function previewReceipt(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $org = get_option('dono_org_profile', []);
        if (! is_array($org)) $org = [];

        $donor                    = \Dono\Donors\Donor::make();
        $donor->id                = 0;
        $donor->email_hash        = 'preview';
        $donor->email_encrypted   = '';
        $donor->first_name        = 'Sample';
        $donor->last_name         = 'Donor';
        $donor->donor_type        = 'individual';
        $donor->locale            = (string) (determine_locale() ?: 'en_US');
        $donor->total_donated_cents = 0;
        $donor->donations_count     = 0;
        $donor->created_at        = current_time('mysql');
        $donor->updated_at        = current_time('mysql');

        $donation                 = \Dono\Donations\Donation::make();
        $donation->id             = 0;
        $donation->reference      = 'PREVIEW-0000';
        $donation->donor_id       = 0;
        $donation->amount_cents   = 5000;
        $donation->net_cents      = 5000;
        $donation->currency       = strtoupper((string) \Dono\Foundation\Helpers\Money::defaultCurrency());
        $donation->frequency      = 'one_time';
        $donation->status         = 'paid';
        $donation->gateway        = 'offline';
        $donation->created_at     = current_time('mysql');
        $donation->updated_at     = current_time('mysql');
        $donation->paid_at        = current_time('mysql');

        $ctx = new \Dono\Receipts\ReceiptContext(
            donation:      $donation,
            donor:         $donor,
            locale:        $donor->locale,
            org:           $org,
            donor_email:   'sample.donor@example.com',
            donor_address: null,
            donor_name:    'Sample Donor',
        );

        try {
            $pdf = $this->genericRenderer->render($ctx);
        } catch (\Throwable $e) {
            return new WP_Error('dono_render_failed', $e->getMessage(), ['status' => 500]);
        }

        $route = $request->get_route();
        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $pdf) {
            if ((string) $req->get_route() !== $route) return $served;

            $server->send_header('Content-Type', 'application/pdf');
            $server->send_header('Content-Disposition', 'inline; filename="receipt-preview.pdf"');
            $server->send_header('Content-Length', (string) strlen($pdf));
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            echo $pdf;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-preview.pdf"',
        ]);
        return $response;
    }

    /** @since 1.0.0 */
    public function exportCsv(WP_REST_Request $request): WP_REST_Response
    {
        // CSV via rest_pre_serve_request to bypass the JSON serializer, written
        // straight to the socket: a full export held as one string costs orders
        // of magnitude more memory than the CSV it produces and exhausts the
        // memory limit well inside EXPORT_MAX_ROWS.
        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($request) {
            $route = $request->get_route();
            if ((string) $req->get_route() !== $route) {
                return $served;
            }

            $filename = 'donations-' . gmdate('Y-m-d-His') . '.csv';

            $server->send_header('Content-Type', 'text/csv; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'no-cache, no-store, must-revalidate');
            $server->send_header('Pragma', 'no-cache');
            $server->send_header('Expires', '0');

            $out = fopen('php://output', 'w');
            if ($out !== false) {
                $this->writeCsv($out, $request);
                fclose($out);
            }

            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers(['Content-Type' => 'text/csv; charset=utf-8']);
        return $response;
    }

    /**
     * @param resource $out
     *
     * @since 1.0.0
     */
    private function writeCsv($out, WP_REST_Request $request): void
    {
        $search  = $request['search']   !== null ? trim((string) $request['search']) : '';
        $donorId = $request['donor_id'] !== null ? (int) $request['donor_id'] : 0;

        $matchingDonorIds = $donorId > 0
            ? []
            : ($search !== '' ? $this->donorService->findIdsBySearch($search) : []);

        $filters = [
            'orderby'            => (string) ($request['orderby'] ?? 'created_at'),
            'order'              => (string) ($request['order']   ?? 'desc'),
            'status'             => $request['status'] !== null ? (string) $request['status'] : null,
            'search'             => $search !== '' ? $search : null,
            'matching_donor_ids' => $matchingDonorIds,
            'donor_id'           => $donorId,
            'campaign_id'        => $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
            'form_id'            => $request['form_id']     !== null ? (int) $request['form_id']     : null,
            'gateway'            => $request['gateway']     !== null ? (string) $request['gateway'] : null,
            'frequency'          => $request['frequency']   !== null ? (string) $request['frequency'] : null,
            'is_test'            => $request['is_test']     !== null ? (bool) $request['is_test']    : null,
            'include_test'       => (bool) $request['include_test'],
            'created_from'       => $request['created_from'] !== null ? (string) $request['created_from'] : null,
            'created_to'         => $request['created_to']   !== null ? (string) $request['created_to']   : null,
        ];

        // Whether this caller may take donor identities away in bulk. The rest
        // of the file is donation records, which dono_view_donations covers;
        // the name and email columns are the donor list by another route, and
        // that is what dono_export_donors exists to gate.
        $withDonorPii = Capabilities::userCan('dono_export_donors');

        // UTF-8 BOM so Excel auto-detects the encoding for accented donor names.
        fwrite($out, "\xEF\xBB\xBF");

        Csv::writeRow($out, array_merge([
            __('Reference', 'dono'),
            __('Status', 'dono'),
            __('Amount', 'dono'),
            __('Currency', 'dono'),
            __('Base amount', 'dono'),
            __('Base currency', 'dono'),
            __('Fee', 'dono'),
            __('Net', 'dono'),
            // Its own column rather than netted off Net: Net is the amount less
            // the processing fee, which is what the gateway settled, so a row
            // refunded afterwards has to carry both figures to reconcile.
            __('Refunded', 'dono'),
            __('Gateway', 'dono'),
            __('Frequency', 'dono'),
            __('Fund', 'dono'),
            __('Country', 'dono'),
        ], $withDonorPii ? [
            __('Donor name', 'dono'),
            __('Donor email', 'dono'),
        ] : [], [
            __('Created at', 'dono'),
            __('Paid at', 'dono'),
            __('Refunded at', 'dono'),
        ]));

        $ids = $this->donations->listIdsForExport($filters + ['limit' => self::EXPORT_MAX_ROWS]);

        foreach (array_chunk($ids, self::EXPORT_PAGE) as $idChunk) {
            $byId = $this->donations->findManyDonationsByIds($idChunk);

            $rows = [];
            foreach ($idChunk as $id) {
                if (isset($byId[$id])) $rows[] = $byId[$id];
            }

            // Per page, so the cache is bounded by the page rather than by the
            // number of distinct donors in the whole export.
            $donorCache = [];
            foreach (array_chunk(array_unique(array_map(
                static fn ($d): int => (int) $d->donor_id,
                $rows
            )), 500) as $chunk) {
                $donorCache += $this->donors->findManyByIds($chunk);
            }

            $fundIds = array_values(array_filter(array_unique(array_map(
                static fn ($d): int => (int) $d->fund_id,
                $rows
            ))));
            $fundNames = [];
            if ($fundIds !== []) {
                foreach (Fund::query()->whereIn('id', $fundIds)->getAll() as $f) {
                    $fundNames[(int) $f->id] = (string) $f->name;
                }
            }

            foreach ($rows as $d) {
                /** @var Donation $d */
                $donor = $donorCache[(int) $d->donor_id] ?? null;
                Csv::writeRow($out, array_merge([
                    $d->reference,
                    $d->status,
                    number_format($d->amount_cents / 100, 2, '.', ''),
                    strtoupper($d->currency),
                    $d->base_amount_cents !== null ? number_format($d->base_amount_cents / 100, 2, '.', '') : '',
                    $d->base_currency !== null ? strtoupper($d->base_currency) : '',
                    number_format($d->fee_cents / 100, 2, '.', ''),
                    number_format($d->net_cents / 100, 2, '.', ''),
                    number_format($d->refunded_cents / 100, 2, '.', ''),
                    $d->gateway,
                    $d->frequency,
                    $fundNames[(int) $d->fund_id] ?? '',
                    $d->country ?? '',
                ], $withDonorPii ? [
                    $donor ? $this->donorName($donor) : '',
                    $donor ? $this->donorService->decryptEmail($donor) : '',
                ] : [], [
                    $d->created_at,
                    $d->paid_at ?? '',
                    $d->refunded_at ?? '',
                ]));
            }

            unset($rows, $byId, $donorCache, $fundNames);
            flush();
        }
    }

    /** @since 1.0.0 */
    private function shapeDonation(Donation $d, ?Donor $donor, ?Campaign $campaign = null, ?Form $form = null, ?Fund $fund = null): array
    {
        return [
            'id'           => $d->id,
            'reference'    => $d->reference,
            'amount_cents' => $d->amount_cents,
            'currency'     => $d->currency,
            'base_amount_cents' => $d->base_amount_cents !== null ? (int) $d->base_amount_cents : null,
            'base_currency'     => $d->base_currency,
            'status'       => $d->status,
            'gateway'      => $d->gateway,
            'frequency'    => $d->frequency,
            'is_test'      => (bool) $d->is_test,
            'country'      => $d->country,
            'paid_at'      => $d->paid_at,
            'created_at'   => $d->created_at,
            'donor'        => $donor ? [
                'id'       => $donor->id,
                'name'     => $this->donorName($donor),
                // Erasure means the address is gone: do not hand it back here,
                // and let the row's actions see that there is nobody to email.
                'redacted' => $donor->redacted_at !== null,
                'email'    => $donor->redacted_at === null && $this->canReadDonorPii()
                    ? $this->donorService->decryptEmail($donor)
                    : null,
                'country'  => $donor->country,
            ] : null,
            'campaign'     => $campaign ? [
                'id'    => (int) $campaign->id,
                'title' => (string) $campaign->title,
            ] : null,
            'form'         => $form ? [
                'id'    => (int) $form->id,
                'title' => (string) $form->title,
            ] : null,
            // Every donation is assigned one by FundResolver, so the
            // designation the donor picked is shown back here.
            'fund'         => $fund ? [
                'id'   => (int) $fund->id,
                'name' => (string) $fund->name,
                'code' => (string) $fund->code,
            ] : null,
        ];
    }

    /**
     * Whether the caller may read donor contact details. Paging the donations
     * list one email at a time is the donor list by another route, and that is
     * what dono_view_donors gates; the donation record itself stays readable on
     * dono_view_donations alone.
     *
     * @since 1.0.0
     */
    private function canReadDonorPii(): bool
    {
        return Capabilities::userCan('dono_view_donors');
    }

    /** @since 1.0.0 */
    private function donorName(Donor $d): string
    {
        $full = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''));
        return $full !== '' ? $full : '-';
    }

    /** @since 1.0.0 */
    private function baseCurrency(): string
    {
        $cur = (new SettingsService())->get('currency-locale');
        return strtoupper((string) ($cur['default_currency'] ?? 'USD'));
    }


    /**
     * Gateway filter options: the distinct slugs actually present in the
     * donations table, scoped the way the list is.
     *
     * From the data rather than from GatewayManager, because the two sets
     * differ in both directions. A slug outlives its gateway being
     * disconnected or its add-on being deactivated, and the Give importer
     * writes slugs core never registers.
     *
     * PaymentGateway::label() is deliberately unused: that is donor-facing
     * payment-method copy, and several gateways answer "Credit card", which
     * would put identical options in one dropdown.
     *
     * @since 1.0.0
     */
    public function gatewayOptions(WP_REST_Request $request): WP_REST_Response
    {
        // gateway is NOT NULL varchar(32); '' is the only empty case.
        $q = Donation::query()->distinct()->whereRaw("gateway <> ''");
        if (! $request['include_test']) {
            $q = DonationQueries::live($q);
        }

        $slugs = array_values(array_filter(array_map(
            'strval',
            $q->orderBy('gateway', 'ASC')->pluck('gateway')
        )));

        return new WP_REST_Response(array_map(
            static fn (string $slug): array => [
                'value' => $slug,
                'label' => self::gatewayLabel($slug),
            ],
            $slugs
        ), 200);
    }

    /**
     * Admin-facing display name for a gateway slug. Core names what it ships
     * plus the slugs the Give importer writes; add-ons name their own through
     * the filter; an unnamed slug still gets a usable option rather than being
     * dropped.
     *
     * @since 1.0.0
     */
    public static function gatewayLabel(string $slug): string
    {
        $known = [
            'stripe'  => __('Stripe', 'dono'),
            'paypal'  => __('PayPal', 'dono'),
            'offline' => __('Offline', 'dono'),
            'sandbox' => __('Test donation', 'dono'),
            'manual'  => __('Manually entered', 'dono'),
        ];

        if (isset($known[$slug])) {
            return $known[$slug];
        }

        $added = (array) apply_filters('dono.gateway_admin_labels', []);
        $label = $added[$slug] ?? null;

        return is_string($label) && $label !== ''
            ? $label
            : ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /** @since 1.0.0 */
    private function indexArgs(): array
    {
        return [
            'page'         => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page'     => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
            'orderby'      => ['type' => 'string', 'default' => 'created_at'],
            'order'        => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
            'status'       => ['type' => 'string'],
            'search'       => ['type' => 'string'],
            'donor_id'     => ['type' => 'integer', 'minimum' => 1],
            'campaign_id'  => ['type' => 'integer', 'minimum' => 1],
            'form_id'      => ['type' => 'integer', 'minimum' => 1],
            'gateway'      => ['type' => 'string'],
            // 'recurring' means any cadence; the rest match the stored value.
            'frequency'    => ['type' => 'string', 'enum' => ['recurring', 'one_time', 'weekly', 'monthly', 'quarterly', 'yearly']],
            'is_test'      => ['type' => 'boolean'],
            // Widens the scope to both kinds. is_test filters to one of them,
            // so the two are different questions and the explicit filter wins.
            'include_test' => ['type' => 'boolean', 'default' => false],
            'created_from' => ['type' => 'string'],
            'created_to'   => ['type' => 'string'],
        ];
    }
}
