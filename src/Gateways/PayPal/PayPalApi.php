<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use RuntimeException;

/**
 * Thin PayPal REST API wrapper.
 *
 * Unlike Stripe, PayPal does not accept the secret directly on API calls: the
 * client id + secret are exchanged for a short-lived OAuth2 bearer token, which
 * is cached per mode. Sandbox and live are different hosts entirely.
 *
 * @version 1.0.0
 */
final class PayPalApi
{
    private const LIVE_BASE    = 'https://api-m.paypal.com';
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
    private const TIMEOUT      = 15;

    public function __construct(private PayPalAccount $account)
    {
    }

    /** True when the active mode has credentials. */
    public function isConfigured(): bool
    {
        return $this->account->hasKeysFor($this->account->isTestMode());
    }

    /** API host for the active mode. Sandbox and live are separate systems. */
    public function baseUrl(): string
    {
        return $this->account->isTestMode() ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    /**
     * Exchange client id + secret for a bearer token, cached until shortly
     * before it expires.
     *
     * @throws RuntimeException when PayPal rejects the credentials.
     */
    public function accessToken(): string
    {
        $test = $this->account->isTestMode();

        $cached = $this->account->cachedToken($test);
        if ($cached !== '') {
            return $cached;
        }

        $clientId = $this->account->activeClientId();
        $secret   = $this->account->activeSecret();
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException(sprintf(
                'PayPal has no %s credentials. Add them in Settings, Gateways.',
                $test ? 'sandbox' : 'live'
            ));
        }

        $response = wp_remote_post($this->baseUrl() . '/v1/oauth2/token', [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $secret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'    => 'grant_type=client_credentials',
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('PayPal transport error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200 || ! is_array($body) || ! isset($body['access_token'])) {
            $msg = is_array($body)
                ? (string) ($body['error_description'] ?? $body['message'] ?? "HTTP {$code}")
                : "HTTP {$code}";
            throw new RuntimeException('PayPal rejected the credentials: ' . $msg);
        }

        $token = (string) $body['access_token'];
        $this->account->cacheToken($test, $token, (int) ($body['expires_in'] ?? 3200));

        return $token;
    }

    /** @return array<string,mixed> */
    public function post(string $path, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /** @return array<string,mixed> */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** @return array<string,mixed> */
    public function patch(string $path, array $body = [], array $headers = []): array
    {
        return $this->request('PATCH', $path, $body, $headers);
    }

    /**
     * PayPal speaks JSON (unlike Stripe's form encoding). A 204 is a success
     * with no body, which several subscription actions return.
     *
     * @return array<string,mixed>
     * @throws RuntimeException on transport failure or a >=400 response.
     */
    private function request(string $method, string $path, ?array $body, array $extraHeaders): array
    {
        if (! $this->isConfigured()) {
            $mode = $this->account->isTestMode() ? 'sandbox' : 'live';
            throw new RuntimeException(sprintf(
                'PayPal has no %s credentials. Add them in Settings, Gateways.',
                $mode
            ));
        }

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ], $extraHeaders),
        ];
        if ($body !== null) {
            $args['body'] = (string) wp_json_encode($body);
        }

        $response = wp_remote_request($this->baseUrl() . $path, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException('PayPal transport error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        if ($raw === '' && $code >= 200 && $code < 300) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("PayPal returned a non-JSON response (HTTP {$code}): " . substr($raw, 0, 200));
        }

        if ($code >= 400) {
            throw new PayPalApiException(
                'PayPal API: ' . $this->errorMessage($decoded, $code),
                PayPalApiException::issuesFrom($decoded)
            );
        }

        return $decoded;
    }

    /**
     * PayPal nests the useful detail in `details[]`; the top-level message is
     * often just "The requested action could not be performed".
     */
    private function errorMessage(array $body, int $code): string
    {
        $msg = (string) ($body['message'] ?? $body['error_description'] ?? "HTTP {$code}");

        $details = $body['details'] ?? null;
        if (is_array($details) && $details !== []) {
            $parts = [];
            foreach ($details as $d) {
                if (! is_array($d)) continue;
                $parts[] = trim((string) ($d['description'] ?? $d['issue'] ?? ''));
            }
            $parts = array_values(array_filter($parts));
            if ($parts !== []) {
                $msg .= ' (' . implode('; ', $parts) . ')';
            }
        }
        return $msg;
    }

    /**
     * Verify a webhook by asking PayPal to check the signature.
     *
     * PayPal has no shared-secret HMAC like Stripe: verification is an API call
     * that replays the transmission headers plus the webhook id. Without a
     * stored webhook id we cannot verify, and must reject rather than trust.
     *
     * @param array<string,string> $headers PAYPAL-* transmission headers.
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $webhookId = $this->account->webhookId($this->account->isTestMode());
        if ($webhookId === '') {
            return false;
        }

        $needed = ['transmission_id', 'transmission_time', 'cert_url', 'auth_algo', 'transmission_sig'];
        foreach ($needed as $k) {
            if (($headers[$k] ?? '') === '') {
                return false;
            }
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            return false;
        }

        try {
            $result = $this->post('/v1/notifications/verify-webhook-signature', [
                'auth_algo'         => $headers['auth_algo'],
                'cert_url'          => $headers['cert_url'],
                'transmission_id'   => $headers['transmission_id'],
                'transmission_sig'  => $headers['transmission_sig'],
                'transmission_time' => $headers['transmission_time'],
                'webhook_id'        => $webhookId,
                'webhook_event'     => $event,
            ]);
        } catch (RuntimeException $e) {
            return false;
        }

        return ($result['verification_status'] ?? '') === 'SUCCESS';
    }
}
