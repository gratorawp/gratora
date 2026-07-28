<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Campaigns\Campaign;
use Dono\Currency\Currency;
use Dono\Donations\AntiSpamGuard;
use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\ConsentService;
use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Rest\Schemas\DonationSchemas;
use Dono\Settings\SettingsService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DonationsController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private DonationService $donations,
        private DonationRepository $repository,
        private GatewayManager $gateways,
        private AntiSpamGuard $spam,
        private ConsentService $consents,
        private SettingsService $settings,
    ) {
    }

    /** Registers all public donation routes. */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/donations', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create'],
            'permission_callback' => '__return_true',  // public donation form
            'args'                => $this->createArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/donations/(?P<reference>[A-Za-z0-9_\-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getStatus'],
            'permission_callback' => '__return_true',
            'args'                => [
                'reference'    => ['type' => 'string', 'required' => true],
                'status_token' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/donations/(?P<reference>[A-Za-z0-9_\-]+)/confirm', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'confirm'],
            'permission_callback' => [$this, 'confirmPermission'],
            'args'                => [
                'reference' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** Creates a pending donation and returns gateway intent data. */
    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) $request->get_json_params();

        // Anti-spam gates, cheapest first; failures return generic 400/429.
        if ($err = $this->spam->checkHoneypot((string) ($body['_hp'] ?? ''))) return $err;
        if ($err = $this->spam->verifyFormToken((string) ($body['_ft'] ?? ''), (int) ($body['form_id'] ?? 0))) return $err;
        if ($err = $this->spam->consumeIpQuota()) return $err;

        $email      = (string) ($body['email'] ?? '');
        $amount     = (int)    ($body['amount_cents'] ?? 0);
        $currency   = strtoupper((string) ($body['currency'] ?? ''));
        $gatewayId  = (string) ($body['gateway'] ?? '');

        if ($email === '' || ! is_email($email)) {
            return new WP_Error('dono_invalid_email', __('A valid email is required.', 'dono'), ['status' => 400]);
        }
        if ($amount <= 0) {
            return new WP_Error('dono_invalid_amount', __('Amount must be a positive integer (in cents).', 'dono'), ['status' => 400]);
        }
        if ($err = $this->spam->checkMinAmount($amount)) return $err;
        if (strlen($currency) !== 3) {
            return new WP_Error('dono_invalid_currency', __('Currency must be a 3-letter ISO code.', 'dono'), ['status' => 400]);
        }
        // Enforce the org's accepted-currency allow-list server-side. The form
        // currency switcher only offers these, but a crafted payload could
        // submit any code; donations in an unsupported currency have no base
        // conversion, so reject them rather than record an unreportable row.
        if (! $this->isSupportedCurrency($currency)) {
            return new WP_Error('dono_unsupported_currency', __('This currency is not accepted.', 'dono'), ['status' => 400]);
        }
        // Zero-decimal currencies (JPY, KRW, ...) have no sub-unit. Storage is
        // always major x 100, so the amount must land on a whole major unit or
        // the gateway conversion rounds and mischarges.
        if (Currency::minorUnits($currency) === 0 && $amount % 100 !== 0) {
            return new WP_Error('dono_invalid_amount', __('This currency does not support fractional amounts.', 'dono'), ['status' => 400]);
        }
        if ($gatewayId === '' || ! $this->gateways->get($gatewayId)) {
            /* translators: %s: gateway identifier */
            return new WP_Error('dono_invalid_gateway', sprintf(__('Unknown gateway: %s', 'dono'), $gatewayId), ['status' => 400]);
        }
        if ($err = $this->spam->consumeEmailQuota($email)) return $err;

        $profile = (array) ($body['profile'] ?? []);
        $country = $body['country'] ?? ($profile['country'] ?? null);

        $formId   = isset($body['form_id']) ? (int) $body['form_id'] : null;
        $formType = 'donation';
        $form     = null;
        if ($formId !== null) {
            $form = Form::query()->where('id', $formId)->get();
            // A form_id that no longer resolves must not skip the gates below:
            // form tokens stay valid for days, so a deleted form's token (or a
            // stubbed preview id) would otherwise bypass the status gates and
            // the whole block-level validator.
            if (! $form) {
                return new WP_Error(
                    'dono_form_not_available',
                    __('This form is not accepting donations.', 'dono'),
                    ['status' => 403]
                );
            }
            if ($form) {
                // Mirror the public render gate: only published forms take donations.
                if ($form->status !== 'published') {
                    return new WP_Error(
                        'dono_form_not_available',
                        __('This form is not accepting donations.', 'dono'),
                        ['status' => 403]
                    );
                }
                // The render gate applies the same rule; enforce it here too so
                // an archived, unpublished or out-of-schedule campaign stops
                // taking donations. Otherwise a stale (day-bucketed) form token
                // or a direct POST lands on the closed campaign, inflating its
                // totals and re-arming the delete guard so it can't be removed.
                if ($form->campaign_id) {
                    $campaign = Campaign::query()->find('id', (int) $form->campaign_id);
                    if (! $campaign || ! $campaign->acceptsDonations()) {
                        return new WP_Error(
                            'dono_campaign_not_available',
                            __('This campaign is not accepting donations.', 'dono'),
                            ['status' => 403]
                        );
                    }
                }
                $formType = $form->form_type;

                $invalid = (new FormSubmissionValidator())->validate($form, $body);
                if ($invalid !== null) {
                    return $invalid;
                }

                // A fund choice is only meaningful when the form offered one;
                // otherwise a crafted POST routes money to any active fund the
                // form never listed. Cleared, the resolver falls back to the
                // form/campaign/org default chain.
                if (! FormSubmissionValidator::hasBlock((string) ($form->blocks ?? ''), 'dono/fund-picker')) {
                    unset($body['fund_id']);
                }
            }
        }

        // Gateway must be one the form actually offers in this context, not
        // just any registered gateway. Closes a crafted-payload hole.
        $formAllowed = ($form && is_array($form->settings['gateways']['allowed'] ?? null))
            ? $form->settings['gateways']['allowed']
            : [];
        $allowedGateways = $this->gateways->optionsFor(
            $formAllowed,
            $country !== null ? (string) $country : null,
            $currency,
            (string) ($body['frequency'] ?? 'one_time')
        );
        if (! in_array($gatewayId, $allowedGateways, true)) {
            return new WP_Error(
                'dono_gateway_not_allowed',
                __('That payment method is not available for this form.', 'dono'),
                ['status' => 400]
            );
        }
        $clientExtra = is_array($body['extra'] ?? null) ? $body['extra'] : [];
        // Attribution ids are derived server-side from the signed fundraiser
        // context (the p2p add-on validates extra['fundraiser_ctx'] on
        // dono.donation.intent_creating and stamps these). A public caller must
        // never supply them directly, or it could credit an arbitrary
        // fundraiser's / team's totals and leaderboard rank. The signed
        // fundraiser_ctx is preserved for the add-on to validate.
        unset($clientExtra['fundraiser_id'], $clientExtra['fundraiser_team_id']);
        $extra = array_merge(
            $clientExtra,
            ['form_type' => $formType],
        );
        $custom = is_array($body['custom'] ?? null) ? $body['custom'] : [];

        // Bound the unauthenticated blob inputs by encoded size. source_attribution
        // is persisted verbatim and custom is AES-encrypted; the per-IP cap limits
        // volume, not per-request size, so cap the bytes here.
        $sourceAttribution = isset($body['source_attribution']) ? (array) $body['source_attribution'] : null;
        if ($sourceAttribution !== null && strlen((string) wp_json_encode($sourceAttribution)) > 4096) {
            return new WP_Error('dono_attribution_too_large', __('Attribution data is too large.', 'dono'), ['status' => 400]);
        }
        if ($custom !== [] && strlen((string) wp_json_encode($custom)) > 16384) {
            return new WP_Error('dono_custom_too_large', __('Submitted form data is too large.', 'dono'), ['status' => 400]);
        }

        $intent = new DonationIntent(
            email:              $email,
            amount_cents:       $amount,
            currency:           $currency,
            gateway:            $gatewayId,
            frequency:          (string) ($body['frequency'] ?? 'one_time'),
            form_id:            $formId,
            campaign_id:        $this->resolveCampaignId($form, $body['campaign_id'] ?? null),
            fund_id:            isset($body['fund_id']) ? (int) $body['fund_id'] : null,
            profile:            $profile,
            payment_method:     isset($body['payment_method']) ? (string) $body['payment_method'] : null,
            source_attribution: $sourceAttribution,
            locale:             isset($body['locale']) ? (string) $body['locale'] : null,
            note_to_org:        isset($body['note_to_org']) ? (string) $body['note_to_org'] : null,
            note_public:        ! empty($body['note_public']),
            is_anonymous:       (bool) ($body['is_anonymous'] ?? false),
            country:            $country !== null ? (string) $country : null,
            tribute:            $this->normalizeTribute($body['tribute'] ?? null),
            fee_covered_cents:  min($amount, max(0, (int) ($body['fee_covered_cents'] ?? 0))),
            extra:              $extra,
            custom:             $custom,
        );

        $this->debugLog("donation submit: gateway={$gatewayId} amount={$intent->amount_cents} currency={$intent->currency}");

        try {
            $created = $this->donations->createPending($intent);
        } catch ( Throwable $e) {
            // Never leak DB/gateway internals to the public client.
            $this->debugLog('donation create failed: ' . $e->getMessage());
            return new WP_Error(
                'dono_create_failed',
                __('We could not process your donation just now. Please try again.', 'dono'),
                ['status' => 500]
            );
        }

        $donation       = $created['donation'];
        $rawStatusToken = $created['status_token'];

        // Record any consents the donor gave on the form (GDPR/marketing
        // opt-ins) as append-only audit rows tied to this donation. Only known
        // purposes are recorded; a consent write must never break the donation.
        $consents = is_array($body['consents'] ?? null) ? $body['consents'] : [];
        if ($consents && $donation->donor_id) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            // Accept the form's own consent-block purposes (keyed by id), not
            // just the org settings registry, or form-defined consents drop.
            $formConsentIds = $form ? FormSubmissionValidator::consentPurposeIds((string) $form->blocks) : [];
            foreach ($consents as $key => $granted) {
                $key = (string) $key;
                if ($this->consents->findPurpose($key) === null && ! isset($formConsentIds[$key])) continue;
                try {
                    $this->consents->record((int) $donation->donor_id, $key, (bool) $granted, [
                        'source'      => 'donation',
                        'form_id'     => $formId,
                        'donation_id' => (int) $donation->id,
                        'ip'          => $ip,
                        'ua'          => $ua,
                    ]);
                } catch (Throwable $e) {
                    $this->debugLog('consent record failed: ' . $e->getMessage());
                }
            }
        }

        $gateway = $this->gateways->require($gatewayId);

        try {
            $gatewayResult = $gateway->createIntent($donation);
        } catch ( Throwable $e) {
            $this->debugLog("gateway createIntent failed: {$e->getMessage()}");
            $this->donations->markFailed($donation, 'Gateway createIntent threw: ' . $e->getMessage());
            return new WP_Error('dono_gateway_intent_failed', __('We could not start your payment. Please try again in a moment.', 'dono'), ['status' => 502]);
        }

        try {
            $donation = $this->donations->setGatewayIntent(
                $donation,
                $gatewayResult->intent_id,
                $gatewayResult->metadata
            );
        } catch (Throwable $e) {
            $this->donations->markFailed($donation, 'setGatewayIntent failed: ' . $e->getMessage());
            return new WP_Error('dono_intent_persist_failed', __('Something went wrong saving your donation. Please try again.', 'dono'), ['status' => 500]);
        }

        if ($gatewayResult->requires_action) {
            $this->donations->markPending($donation, 'requires_action', (array) $gatewayResult->metadata);
        }

        // Synchronous gateways (e.g. sandbox) have no redirect / no webhook,
        // so the donation would otherwise stay pending forever. Confirm in
        // the same request so the donor sees a real paid donation and the
        // receipt + rollup side effects fire.
        if ($gatewayResult->auto_confirm && $donation->status === 'pending') {
            try {
                $confirmResult = $gateway->confirm($donation, []);
                if ($confirmResult->success) {
                    $donation = $this->donations->confirm($donation, $confirmResult->toArray());
                } else {
                    $this->donations->markFailed($donation, 'Sync gateway confirm returned !success.');
                }
            } catch (Throwable $e) {
                $this->donations->markFailed($donation, 'Sync gateway confirm threw: ' . $e->getMessage());
            }
        }

        return new WP_REST_Response([
            'reference'       => $donation->reference,
            'status_token'    => $rawStatusToken,
            'status'          => $donation->status,
            'amount_cents'    => $donation->amount_cents,
            'currency'        => $donation->currency,
            'gateway'         => $donation->gateway,
            'intent_id'       => $gatewayResult->intent_id,
            'redirect_url'    => $gatewayResult->redirect_url,
            'client_secret'   => $gatewayResult->client_secret,
            'requires_action' => $gatewayResult->requires_action,
            'paypal'          => $this->payPalPayload($gatewayResult),
            'razorpay'        => $this->razorpayPayload($gatewayResult),
        ], 201);
    }

    /**
     * The handful of Razorpay fields Checkout needs. Explicit whitelist, same
     * rule as PayPal: gateway metadata never gets echoed to the browser
     * wholesale.
     *
     * @return array{kind:string,order_id:string,subscription_id:string}|null
     */
    private function razorpayPayload(GatewayIntentResult $result): ?array
    {
        $meta = $result->metadata ?? [];
        $kind = (string) ($meta['razorpay_kind'] ?? '');
        if ($kind === '') {
            return null;
        }

        return [
            'kind'            => $kind,
            'order_id'        => (string) ($meta['razorpay_order_id'] ?? ''),
            'subscription_id' => (string) ($meta['razorpay_subscription_id'] ?? ''),
        ];
    }

    /**
     * The handful of PayPal fields the SDK buttons need. Kept to an explicit
     * whitelist rather than echoing the gateway metadata, so a future gateway
     * field cannot leak to the browser by accident.
     *
     * @return array{kind:string,order_id:string,plan_id:string}|null
     */
    private function payPalPayload(GatewayIntentResult $result): ?array
    {
        $meta = $result->metadata ?? [];
        $kind = (string) ($meta['paypal_kind'] ?? '');
        if ($kind === '') {
            return null;
        }

        return [
            'kind'     => $kind,
            'order_id' => (string) ($meta['paypal_order_id'] ?? ''),
            'plan_id'  => (string) ($meta['paypal_plan_id'] ?? ''),
        ];
    }

    /**
     * Requires `status_token` to prevent reference enumeration. Token mismatch
     * returns the same 404 as not-found so existing references don't leak.
     */
    public function getStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $reference  = (string) $request['reference'];
        $rawToken   = trim((string) ($request['status_token'] ?? ''));
        $notFound   = new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);

        if ($rawToken === '') return $notFound;

        $donation = $this->repository->findByReference($reference);
        if (! $donation) return $notFound;

        $expected = (string) $donation->status_token_hash;
        $provided = hash('sha256', $rawToken);
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return $notFound;
        }

        return new WP_REST_Response([
            'reference'    => $donation->reference,
            'status'       => $donation->status,
            'amount_cents' => $donation->amount_cents,
            'currency'     => $donation->currency,
            'paid_at'      => $donation->paid_at,
        ], 200);
    }

    /** Admin-only synchronous confirmation for offline/manual payments. */
    public function confirm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donation = $this->repository->findByReference((string) $request['reference']);
        if (! $donation) {
            return new WP_Error('dono_not_found', __('Donation not found.', 'dono'), ['status' => 404]);
        }

        if ($donation->status === 'paid') {
            return new WP_REST_Response([
                'reference'      => $donation->reference,
                'status'         => $donation->status,
                'amount_cents'   => $donation->amount_cents,
                'currency'       => $donation->currency,
                'paid_at'        => $donation->paid_at,
                'gateway_txn_id' => $donation->gateway_txn_id,
            ], 200);
        }
        if (! in_array($donation->status, ['pending', 'failed'], true)) {
            return new WP_Error(
                'dono_invalid_transition',
                sprintf(
                    /* translators: %s: current donation status. */
                    __('Cannot confirm a %s donation.', 'dono'),
                    $donation->status
                ),
                ['status' => 422]
            );
        }

        $gateway = $this->gateways->get($donation->gateway);
        if (! $gateway) {
            /* translators: %s: gateway identifier. */
            return new WP_Error('dono_unknown_gateway', sprintf(__('Gateway "%s" is no longer registered.', 'dono'), $donation->gateway), ['status' => 500]);
        }

        $payload = (array) ($request->get_json_params() ?? []);
        $payload['admin_user_id'] = get_current_user_id() ?: null;

        try {
            $result = $gateway->confirm($donation, $payload);
        } catch ( Throwable $e) {
            return new WP_Error('dono_gateway_confirm_failed', __('We could not confirm your payment. Please try again in a moment.', 'dono'), ['status' => 502]);
        }

        if (! $result->success) {
            $this->donations->markFailed($donation, $result->error ?? __('Gateway returned failure.', 'dono'));
            return new WP_Error('dono_confirm_failed', $result->error ?? __('Confirmation failed.', 'dono'), ['status' => 402]);
        }

        $donation = $this->donations->confirm($donation, $result->toArray());

        return new WP_REST_Response([
            'reference'      => $donation->reference,
            'status'         => $donation->status,
            'amount_cents'   => $donation->amount_cents,
            'currency'       => $donation->currency,
            'paid_at'        => $donation->paid_at,
            'gateway_txn_id' => $donation->gateway_txn_id,
        ], 200);
    }

    /**
     * Resolve the campaign a donation credits. A campaign-bound form is
     * authoritative; otherwise a body campaign_id is only trusted when it
     * points at a real campaign. Stops crafted public payloads from inflating
     * an arbitrary campaign's totals + leaderboards.
     *
     * @param mixed $submitted
     */
    /**
     * Is the (uppercased) currency in the org's accepted list? An empty/absent
     * list means "unconfigured" - accept any valid code rather than reject
     * everything.
     */
    private function isSupportedCurrency(string $currency): bool
    {
        $cfg       = $this->settings->get('currency-locale');
        $base      = strtoupper((string) ($cfg['default_currency'] ?? 'USD'));
        $supported = is_array($cfg['supported_currencies'] ?? null) ? $cfg['supported_currencies'] : [];
        $supported = array_map(static fn ($c): string => strtoupper((string) $c), $supported);

        // The base currency is always accepted, even if it was never added to
        // the supported list explicitly.
        return $supported === [] || $currency === $base || in_array($currency, $supported, true);
    }

    private function resolveCampaignId(?Form $form, mixed $submitted): ?int
    {
        if ($form && (int) ($form->campaign_id ?? 0) > 0) {
            return (int) $form->campaign_id;
        }
        $id = (int) $submitted;
        if ($id <= 0) {
            return null;
        }
        // Only campaigns currently open take credit for client-submitted ids;
        // the form-bound path enforces the same gate above, and crediting a
        // closed campaign would inflate its totals and re-arm its delete guard.
        $campaign = Campaign::query()->where('id', $id)->get();
        return ($campaign && $campaign->acceptsDonations()) ? $id : null;
    }

    /**
     * @param mixed $raw
     * @return array{type:string,name:string,notify_email?:?string,message?:?string,convert_to_annual?:bool}|null
     */
    private function normalizeTribute($raw): ?array
    {
        if (! is_array($raw)) return null;
        $type = trim((string) ($raw['type'] ?? ''));
        $name = trim((string) ($raw['name'] ?? ''));
        if ($type === '' || $name === '') return null;
        $out = ['type' => $type, 'name' => $name];
        $notify = trim((string) ($raw['notify_email'] ?? ''));
        if ($notify !== '' && is_email($notify)) $out['notify_email'] = $notify;
        $msg = trim((string) ($raw['message'] ?? ''));
        if ($msg !== '') $out['message'] = $msg;
        if (! empty($raw['convert_to_annual'])) $out['convert_to_annual'] = true;
        return $out;
    }

    public function confirmPermission(): bool
    {
        // Synchronous confirmation is admin-only; webhooks bypass this endpoint.
        return current_user_can('manage_options');
    }

    /**
     * Structural validation lives in DonationSchemas::create(). The handler
     * keeps its own gateway check because the registered set is dynamic.
     */
    private function createArgs(): array
    {
        return DonationSchemas::create();
    }

    private function debugLog(string $message): void
    {
        $cfg = get_option('dono_advanced', []);
        if (is_array($cfg) && ! empty($cfg['debug_logging'])) {
            error_log('[dono] ' . $message);
        }
    }
}
