<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use RuntimeException;

/**
 * Registers Dono's webhook endpoint on the connected account right after a
 * successful connect, so paid / refund / renewal / dispute events flow without
 * the org hand-building a webhook in Stripe. Direct charges fire on the
 * connected account, so the endpoint is created there (via the account's own
 * access token). Best-effort: the caller catches failures, and a
 * local/unreachable site keeps the manual signing-secret path.
 *
 * @version 1.0.0
 */
final class StripeWebhookProvisioner
{
    /** Connected-account events Dono's webhook handler acts on. */
    private const EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'charge.dispute.funds_withdrawn',
        'invoice.payment_succeeded',
        'invoice.payment_failed',
        'customer.subscription.deleted',
        'account.updated',
    ];

    public function __construct(
        private StripeApi $api,
        private StripeConnectAccount $account,
    ) {
    }

    /**
     * Create (or refresh) the webhook endpoint on the just-connected account and
     * store its signing secret for the given mode. Throws on API failure so the
     * caller can log; a thrown error must never block the connect itself.
     */
    public function provision(bool $isTest): void
    {
        // Route token-bearing calls to the mode that was just connected.
        $this->account->useTestMode($isTest);

        $url  = rest_url('dono/v1/webhooks/stripe');
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            // Stripe can't deliver to a local / unresolvable host; leave the
            // manual signing-secret (Stripe CLI) path in place.
            return;
        }

        // Drop any prior Dono endpoint at this URL first: avoids duplicates and
        // yields a fresh secret (Stripe returns the signing secret only on create).
        $existing = $this->api->get('/webhook_endpoints?limit=100');
        foreach ((array) ($existing['data'] ?? []) as $ep) {
            if (! is_array($ep) || ($ep['url'] ?? '') !== $url || ($ep['id'] ?? '') === '') {
                continue;
            }
            try {
                $this->api->delete('/webhook_endpoints/' . rawurlencode((string) $ep['id']));
            } catch (RuntimeException $e) {
                // Non-fatal: a leftover endpoint just means a stale duplicate.
            }
        }

        $created = $this->api->post('/webhook_endpoints', [
            'url'            => $url,
            'enabled_events' => self::EVENTS,
            'description'    => 'Dono',
        ]);

        $secret = (string) ($created['secret'] ?? '');
        if ($secret !== '') {
            $this->storeSecret($isTest, $secret);
        }
    }

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
