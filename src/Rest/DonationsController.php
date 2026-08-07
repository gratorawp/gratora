<?php

declare(strict_types=1);

namespace Dono\Rest;

use Dono\Analytics\ErrorLog;
use Dono\Campaigns\Campaign;
use Dono\Currency\Currency;
use Dono\Currency\SupportedCurrencies;
use Dono\Donations\AntiSpamGuard;
use Dono\Donations\Donation;
use Dono\Donations\ChannelClassifier;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donors\ConsentService;
use Dono\Forms\Form;
use Dono\Forms\Blocks\TermsBlock;
use Dono\Forms\FormSubmissionValidator;
use Dono\Gateways\BrowserAware;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
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
        // The switcher only offers accepted currencies, but a crafted payload
        // could submit any code, and a donation in an unsupported currency has
        // no base conversion and so would be an unreportable row.
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
        // A crafted payload could name a gateway that does not take this
        // currency. Refusing here says so, rather than failing at the gateway
        // with whatever wording it chooses.
        if (! $this->gateways->acceptsCurrency($gatewayId, $currency)) {
            return new WP_Error(
                'dono_gateway_currency',
                sprintf(
                    /* translators: 1: gateway identifier, 2: currency code */
                    __('%1$s cannot take payments in %2$s.', 'dono'),
                    $gatewayId,
                    $currency
                ),
                ['status' => 400]
            );
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
            // form tokens stay valid for days, so a deleted form's token would
            // otherwise bypass the status gates and the block-level validator.
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
                // The render gate applies the same rule, and it is enforced
                // here too: otherwise a stale day-bucketed form token or a
                // direct POST lands on a closed campaign, inflating its totals
                // and re-arming the delete guard so it cannot be removed.
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

                // A fund choice is only meaningful when the form offered one,
                // or a crafted POST routes money to any active fund the form
                // never listed. Cleared, the resolver falls back to the
                // form/campaign/org default chain.
                if (! FormSubmissionValidator::hasBlock((string) ($form->blocks ?? ''), 'dono/fund-picker')) {
                    unset($body['fund_id']);
                }

                // Same rule for the donor's message. note_public puts text on
                // the campaign's supporter wall, so a form with no comment
                // block accepting one is an unmoderated publish route.
                if (! FormSubmissionValidator::hasBlock((string) ($form->blocks ?? ''), 'dono/comment')) {
                    unset($body['note_to_org'], $body['note_public']);
                }
            }
        }

        // The gateway must be one the form actually offers in this context, not
        // just any registered one.
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
        // Attribution ids are stamped server-side from the signed fundraiser
        // context, which is preserved for the add-on to validate. A public
        // caller must never supply them directly, or it could credit an
        // arbitrary fundraiser's totals and leaderboard rank.
        unset($clientExtra['fundraiser_id'], $clientExtra['fundraiser_team_id']);
        $extra = array_merge(
            $clientExtra,
            ['form_type' => $formType],
        );
        $custom = is_array($body['custom'] ?? null) ? $body['custom'] : [];

        // The per-IP cap limits volume, not per-request size, so the
        // unauthenticated blobs are bounded by encoded size here.
        $sourceAttribution = isset($body['source_attribution']) ? (array) $body['source_attribution'] : null;
        if ($sourceAttribution !== null && strlen((string) wp_json_encode($sourceAttribution)) > 4096) {
            return new WP_Error('dono_attribution_too_large', __('Attribution data is too large.', 'dono'), ['status' => 400]);
        }
        // `manual` is reserved for money an admin recorded off the site, and it
        // suppresses the offline payment instructions. This blob comes from the
        // donor's own query string, so a visitor arriving on
        // ?utm_medium=manual would otherwise be denied the bank details.
        if (is_array($sourceAttribution) && strtolower(trim((string) ($sourceAttribution['utm_medium'] ?? ''))) === ChannelClassifier::MANUAL) {
            unset($sourceAttribution['utm_medium']);
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
            fee_covered_cents:  min($amount, max(0, (int) ($body['fee_covered_cents'] ?? 0))),
            extra:              $extra,
            custom:             $custom,
        );

        try {
            $created = $this->donations->createPending($intent);
        } catch ( Throwable $e) {
            // Never leak DB/gateway internals to the public client, so the
            // detail only reaches the admin error log.
            ErrorLog::record('donation.create', $e->getMessage(), [
                'form_id' => $formId,
                'gateway' => $gatewayId,
            ]);
            return new WP_Error(
                'dono_create_failed',
                __('We could not process your donation just now. Please try again.', 'dono'),
                ['status' => 500]
            );
        }

        $donation       = $created['donation'];
        $rawStatusToken = $created['status_token'];

        // Append-only audit rows tied to this donation. Only known purposes are
        // recorded, and a consent write must never break the donation.
        $consents = is_array($body['consents'] ?? null) ? $body['consents'] : [];
        if ($consents && $donation->donor_id) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            // The form's own consent-block purposes as well as the org settings
            // registry, or form-defined consents drop.
            $formConsentIds = $form ? FormSubmissionValidator::consentPurposeIds((string) $form->blocks) : [];
            // Terms are recorded like any other consent, but the version is the
            // revision of the text as it stood, not a number from the purposes
            // registry, so the row still answers "what did they agree to" after
            // the terms are edited.
            $termsRevision = $form ? FormSubmissionValidator::termsRevision((string) $form->blocks) : null;
            foreach ($consents as $key => $granted) {
                $key     = (string) $key;
                $isTerms = $key === TermsBlock::PURPOSE && $termsRevision !== null;
                if (! $isTerms
                    && $this->consents->findPurpose($key) === null
                    && ! isset($formConsentIds[$key])
                ) {
                    continue;
                }
                try {
                    $this->consents->record((int) $donation->donor_id, $key, (bool) $granted, array_filter([
                        'source'      => 'donation',
                        'form_id'     => $formId,
                        'donation_id' => (int) $donation->id,
                        'ip'          => $ip,
                        'ua'          => $ua,
                        'version'     => $isTerms ? $termsRevision : null,
                    ], static fn ($v): bool => $v !== null));
                } catch (Throwable $e) {
                    ErrorLog::record('donation.consent', $e->getMessage(), [
                        'donor_id'    => (int) $donation->donor_id,
                        'donation_id' => (int) $donation->id,
                        'purpose'     => $key,
                    ]);
                }
            }
        }

        $gateway = $this->gateways->require($gatewayId);

        try {
            $gatewayResult = $gateway->createIntent($donation);
        } catch ( Throwable $e) {
            ErrorLog::record('gateway.intent', $e->getMessage(), [
                'donation_id' => (int) $donation->id,
                'gateway'     => $gatewayId,
            ]);
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

        // Synchronous gateways have no redirect and no webhook, so confirming
        // in the same request is what stops the donation sitting pending
        // forever with no receipt and no rollup.
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
            ...$this->browserAwarePayload($gateway, $gatewayResult),
        ], 201);
    }

    /**
     * The browser-facing slice a gateway outside core declares for itself. Same
     * whitelist rule as payPalPayload: the gateway states what may leave, never
     * the raw metadata.
     */
    private function browserAwarePayload(PaymentGateway $gateway, GatewayIntentResult $result): array
    {
        if (! $gateway instanceof BrowserAware) {
            return [];
        }

        try {
            $payload = $gateway->browserPayload($result);
        } catch (Throwable) {
            return [];
        }

        return $payload === null ? [] : [$gateway->id() => $payload];
    }

    /**
     * An explicit whitelist rather than an echo of the gateway metadata, so a
     * future gateway field cannot leak to the browser by accident.
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
        if (! in_array($donation->status, ['pending', 'processing', 'failed'], true)) {
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
     * An empty or absent accepted-currency list means unconfigured, so any
     * valid code is accepted rather than everything rejected.
     */
    private function isSupportedCurrency(string $currency): bool
    {
        return SupportedCurrencies::accepts($currency);
    }

    /**
     * A campaign-bound form is authoritative; otherwise a submitted campaign_id
     * is only trusted when it points at a real campaign, so a crafted payload
     * cannot inflate an arbitrary campaign's totals and leaderboards.
     */
    private function resolveCampaignId(?Form $form, mixed $submitted): ?int
    {
        if ($form && (int) ($form->campaign_id ?? 0) > 0) {
            return (int) $form->campaign_id;
        }
        $id = (int) $submitted;
        if ($id <= 0) {
            return null;
        }
        // Only campaigns currently open take credit for a client-submitted id:
        // crediting a closed one would inflate its totals and re-arm its
        // delete guard.
        $campaign = Campaign::query()->where('id', $id)->get();
        return ($campaign && $campaign->acceptsDonations()) ? $id : null;
    }

    public function confirmPermission(): bool
    {
        // Synchronous confirmation is admin-only; webhooks bypass this endpoint.
        return current_user_can('manage_options');
    }

    /**
     * Structural validation lives in DonationSchemas::create(). The handler
     * keeps its own gateway check, because the registered set is dynamic.
     */
    private function createArgs(): array
    {
        return DonationSchemas::create();
    }

}
