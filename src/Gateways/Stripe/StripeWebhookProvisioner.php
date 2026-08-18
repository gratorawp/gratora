<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use RuntimeException;

/**
 * Registers Dono's webhook endpoint on the organization's own Stripe account
 * after a successful connect, so paid / refund / renewal / dispute events flow
 * without the org hand-building a webhook in Stripe. Charges fire on the
 * account, so the endpoint is created with the account's own secret key.
 *
 * Best-effort: the caller catches failures, and a local or unreachable site
 * keeps the manual signing-secret path.
 *
 * @since 1.0.0
 */
final class StripeWebhookProvisioner
{
    /** Connected-account events Dono's webhook handler acts on. */
    private const EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.processing',
        'payment_intent.payment_failed',
        'charge.refunded',
        // A bank refund is created pending and can still fail, which returns
        // the money to the org while the donor keeps waiting for it.
        'charge.refund.updated',
        'charge.dispute.funds_withdrawn',
        // Won on appeal: Stripe puts the money back, so Dono has to as well.
        'charge.dispute.funds_reinstated',
        'invoice.payment_succeeded',
        'invoice.payment_failed',
        // Where Stripe's own dunning ends: without it a subscription Stripe has
        // stopped collecting stays active here and in MRR for good.
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'account.updated',
    ];

    /** @since 1.0.0 */
    public function __construct(
        private StripeApi $api,
        private StripeAccount $account,
    ) {
    }

    /**
     * Create (or refresh) the webhook endpoint on the account and
     * store its signing secret for the given mode. Throws on API failure so the
     * caller can log; a thrown error must never block the connect itself.
     *
     * @since 1.0.0
     */
    public function provision(bool $isTest): void
    {
        // Route token-bearing calls to the mode that was just connected.
        $this->account->useTestMode($isTest);

        $url = rest_url('dono/v1/webhooks/stripe');
        if (! self::stripeCanReach($url)) {
            // Stripe can't deliver to a local / unresolvable host; provisioning
            // would store a dead endpoint's secret over the manual (Stripe CLI)
            // one and silently break signature checks.
            return;
        }

        $existing = $this->api->get('/webhook_endpoints?limit=100');

        // The whole list first: an endpoint kept without dropping its duplicates
        // leaves them delivering at their own api_version, signed with a secret
        // the site does not hold, until Stripe disables them for failing.
        $matched = [];
        $usable  = [];
        foreach ((array) ($existing['data'] ?? []) as $ep) {
            if (! is_array($ep) || ($ep['url'] ?? '') !== $url || ($ep['id'] ?? '') === '') {
                continue;
            }
            $id        = (string) $ep['id'];
            $matched[] = $id;
            if ($this->isUsable($ep)) {
                $usable[] = $id;
            }
        }

        // An endpoint that already delivers what the handlers read, and whose
        // secret is on file, is what provisioning is for. Recreating it would
        // mint a new signing secret, and every event Stripe is mid-retry on was
        // signed with the old one. Two usable ones are a different matter: the
        // stored secret verifies exactly one and nothing says which, so both go.
        if (count($usable) === 1 && $this->hasSecret($isTest)) {
            $this->deleteEndpoints(array_diff($matched, $usable));

            return;
        }

        // Created before anything is dropped: a create that fails must leave the
        // account with the endpoint and the secret it had, or every donation
        // from that moment is charged with no event to bank it.
        $created = $this->api->post('/webhook_endpoints', [
            'url'            => $url,
            'enabled_events' => self::EVENTS,
            'description'    => 'Dono',
            // Without this the endpoint renders events at whatever the account
            // defaults to, which on any account created since March 2025 is a
            // version that moved fields the handlers read.
            'api_version'    => StripeApi::API_VERSION,
        ]);

        $secret = (string) ($created['secret'] ?? '');
        if ($secret === '') {
            $newId = (string) ($created['id'] ?? '');
            if ($newId !== '') {
                try {
                    $this->api->delete('/webhook_endpoints/' . rawurlencode($newId));
                } catch (RuntimeException $e) {
                    // Best effort: a duplicate endpoint whose secret is unknown
                    // is the state the throw below reports.
                }
            }
            throw new RuntimeException(esc_html('Stripe returned a webhook endpoint with no signing secret.'));
        }

        $this->storeSecret($isTest, $secret);

        $this->deleteEndpoints($matched);
    }

    /**
     * @param iterable<int,string> $ids
     *
     * @since 1.0.0
     */
    private function deleteEndpoints(iterable $ids): void
    {
        foreach ($ids as $id) {
            try {
                $this->api->delete('/webhook_endpoints/' . rawurlencode($id));
            } catch (RuntimeException $e) {
                // Non-fatal: a leftover endpoint just means a stale duplicate.
            }
        }
    }

    /**
     * Whether an endpoint Stripe already has would deliver every event the
     * handlers act on, rendered at the version they read.
     *
     * @param array<string,mixed> $endpoint
     *
     * @since 1.0.0
     */
    private function isUsable(array $endpoint): bool
    {
        if ((string) ($endpoint['status'] ?? '') !== 'enabled') {
            return false;
        }
        if ((string) ($endpoint['api_version'] ?? '') !== StripeApi::API_VERSION) {
            return false;
        }

        $enabled = array_map('strval', (array) ($endpoint['enabled_events'] ?? []));
        if (in_array('*', $enabled, true)) {
            return true;
        }

        return array_diff(self::EVENTS, $enabled) === [];
    }

    /** @since 1.0.0 */
    private function hasSecret(bool $isTest): bool
    {
        $opt    = get_option('dono_gateway_config', []);
        $stripe = is_array($opt) && is_array($opt['stripe'] ?? null) ? $opt['stripe'] : [];

        return (string) ($stripe[$isTest ? 'webhook_secret_test' : 'webhook_secret_live'] ?? '') !== '';
    }

    /**
     * Whether Stripe's servers could plausibly deliver to this URL. Local
     * environments, dev TLDs, and private/loopback addresses cannot receive
     * webhooks, and provisioning against them would overwrite a working
     * manually-entered (Stripe CLI) signing secret with a dead endpoint's.
     *
     * @since 1.0.0
     */
    private static function stripeCanReach(string $url): bool
    {
        if (wp_get_environment_type() === 'local') {
            return false;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost') {
            return false;
        }
        foreach (['.local', '.localhost', '.test', '.internal', '.example', '.invalid'] as $tld) {
            if (str_ends_with($host, $tld)) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }
        return true;
    }

    /** @since 1.0.0 */
    private function storeSecret(bool $isTest, string $secret): void
    {
        $opt = get_option('dono_gateway_config', []);
        if (! is_array($opt)) {
            $opt = [];
        }
        if (! is_array($opt['stripe'] ?? null)) {
            $opt['stripe'] = [];
        }
        $opt['stripe'][$isTest ? 'webhook_secret_test' : 'webhook_secret_live'] = $secret;
        update_option('dono_gateway_config', $opt);
    }
}
