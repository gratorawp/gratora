<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use Dono\Gateways\GatewayTransportException;
use RuntimeException;

/**
 * Thin Stripe REST API wrapper.
 *
 * @since 1.0.0
 */
final class StripeApi
{
    private const API_BASE = 'https://api.stripe.com/v1';
    /**
     * Public because the webhook endpoint has to be created pinned to it.
     * An endpoint with no api_version renders events at the account default,
     * and Stripe moved fields these handlers read in 2025-03-31.basil.
     */
    public const API_VERSION = '2024-12-18.acacia';
    private const TIMEOUT = 10;

    /** @since 1.0.0 */
    public function __construct(private StripeAccount $account)
    {
    }

    /** @since 1.0.0 */
    public function isConfigured(): bool
    {
        return $this->secretKey() !== '';
    }

    /**
     * Per-account access token for the active mode (Bearer auth).
     *
     * @since 1.0.0
     */
    public function secretKey(): string
    {
        return $this->account->activeSecretKey();
    }

    /**
     * Signing secrets by mode. Deliberately keyed rather than flattened: which
     * mode a secret belongs to is what stops a test secret confirming live
     * money, so a secret whose mode is unknown is not usable at all.
     *
     * @return array<string,string> 'test' and/or 'live'
     *
     * @since 1.0.0
     */
    public function webhookSecrets(): array
    {
        return array_filter([
            'test' => $this->gatewayConfig('webhook_secret_test'),
            'live' => $this->gatewayConfig('webhook_secret_live'),
        ], static fn (string $s): bool => $s !== '');
    }

    /**
     * Whether the mode this site charges in has a signing secret.
     *
     * Asking whether ANY secret exists is the same question a site answers
     * after rehearsing in test: the test secret is on file, the live one failed
     * to provision, and every live event then fails its signature while the
     * readiness screen reports that webhooks are signed. Cards are charged,
     * donors are thanked, and the donations sit unpaid.
     *
     * A form can still opt itself into test mode on a live site. That mode's
     * events failing is a nuisance rather than a loss, so the org setting is
     * what this answers for.
     *
     * @since 1.0.0
     */
    public function hasWebhookSecret(): bool
    {
        $secrets = $this->webhookSecrets();

        return ($secrets[$this->orgTestMode() ? 'test' : 'live'] ?? '') !== '';
    }

    /** The org-wide switch, which lives above the per-gateway config. */
    private function orgTestMode(): bool
    {
        $opt = get_option('dono_gateway_config', []);

        return is_array($opt) && ! empty($opt['test_mode']);
    }

    /** @since 1.0.0 */
    public function publishableKey(): string
    {
        return $this->account->activePublishableKey();
    }

    /** @since 1.0.0 */
    public function publishableKeyFor(bool $test): string
    {
        return $this->account->publishableKeyFor($test);
    }

    /** @since 1.0.0 */
    private function gatewayConfig(string $key): string
    {
        $opt = get_option('dono_gateway_config', []);
        if (! is_array($opt)) return '';
        $stripe = is_array($opt['stripe'] ?? null) ? $opt['stripe'] : [];
        return (string) ($stripe[$key] ?? '');
    }

    /**
     * Stripe's API takes application/x-www-form-urlencoded, not JSON.
     *
     * @return array<string,mixed> Decoded JSON response.
     *
     * @since 1.0.0
     */
    public function post(string $path, array $params, array $headers = []): array
    {
        return $this->request('POST', $path, $params, $headers);
    }

    /**
     * @return array<string,mixed> Decoded JSON response.
     *
     * @since 1.0.0
     */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, [], $headers);
    }

    /**
     * @return array<string,mixed> Decoded JSON response.
     *
     * @since 1.0.0
     */
    public function delete(string $path, array $headers = []): array
    {
        return $this->request('DELETE', $path, [], $headers);
    }

    /** @since 1.0.0 */
    private function request(string $method, string $path, array $params, array $extraHeaders): array
    {
        if (! $this->isConfigured()) {
            // Fail closed: a test-mode donation never silently falls back to
            // the live token (activeSecretKey only reads the active mode's
            // field), so be explicit about which connection is missing.
            $mode = $this->account->isTestMode() ? 'test' : 'live';
            throw new RuntimeException(sprintf(
                'Stripe has no %s connection. Connect the %s Stripe account in Settings, Payment gateways.',
                $mode,
                $mode
            ));
        }

        $url = self::API_BASE . '/' . ltrim($path, '/');

        $headers = array_merge([
            'Authorization'   => 'Bearer ' . $this->secretKey(),
            'Stripe-Version'  => self::API_VERSION,
        ], $extraHeaders);

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];

        if ($method === 'POST') {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = $this->buildBody($params);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new GatewayTransportException('Stripe API transport error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Stripe API returned non-JSON response (HTTP {$code}): " . substr($body, 0, 200));
        }

        if ($code >= 400) {
            $msg = $decoded['error']['message'] ?? "Stripe API error (HTTP {$code})";
            throw new RuntimeException("Stripe API: {$msg}");
        }

        return $decoded;
    }

    /**
     * Stripe expects nested params as `bracket[key]=value` form params.
     *
     * @since 1.0.0
     */
    private function buildBody(array $params): string
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Verify a Stripe signature and report which mode's secret matched.
     *
     * Returning the mode rather than a bool is the point: the caller must be
     * able to refuse a test-signed event that claims to be live.
     *
     * @return bool|null null when nothing verified, true when the TEST secret
     *                   matched, false when the LIVE secret matched.
     *
     * @since 1.0.0
     */
    public function verifiedWebhookMode(string $payload, string $sigHeader, int $tolerance = 300): ?bool
    {
        $secrets = $this->webhookSecrets();
        if ($secrets === [] || $sigHeader === '') {
            return null;
        }

        $timestamp  = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) continue;
            $k = trim($kv[0]);
            $v = trim($kv[1]);
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }

        // Replay-attack window.
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return null;
        }

        $signedPayload = "{$timestamp}.{$payload}";

        foreach ($secrets as $mode => $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            foreach ($signatures as $sig) {
                if (hash_equals($expected, $sig)) {
                    return $mode === 'test';
                }
            }
        }

        return null;
    }
}
