<?php

declare(strict_types=1);

namespace Dono\Gateways\Razorpay;

use RuntimeException;

/**
 * Thin Razorpay REST API wrapper.
 *
 * Simpler than PayPal: no token exchange, the key id and secret go straight on
 * as HTTP basic auth. One host serves both environments, and the key prefix
 * decides which one the call lands in.
 *
 * @version 1.0.0
 */
final class RazorpayApi
{
    private const BASE    = 'https://api.razorpay.com';
    private const TIMEOUT = 20;

    public function __construct(private RazorpayAccount $account)
    {
    }

    /** True when the active mode has keys. */
    public function isConfigured(): bool
    {
        return $this->account->hasKeysFor($this->account->isTestMode());
    }

    /** @return array<string,mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /** @return array<string,mixed> */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    /** @return array<string,mixed> */
    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, $body);
    }

    /**
     * @return array<string,mixed>
     * @throws RuntimeException on transport failure or a >=400 response.
     */
    private function request(string $method, string $path, ?array $body): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(sprintf(
                'Razorpay has no %s keys. Add them in Settings, Gateways.',
                $this->account->isTestMode() ? 'test' : 'live'
            ));
        }

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->account->activeKeyId() . ':' . $this->account->activeKeySecret()
                ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = (string) wp_json_encode($body);
        }

        $response = wp_remote_request(self::BASE . $path, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException('Razorpay transport error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        if ($raw === '' && $code >= 200 && $code < 300) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Razorpay returned a non-JSON response (HTTP {$code}): " . substr($raw, 0, 200));
        }

        if ($code >= 400) {
            throw new RuntimeException('Razorpay API: ' . $this->errorMessage($decoded, $code));
        }

        return $decoded;
    }

    /**
     * Razorpay nests everything useful under `error`, and its `description` is
     * the one field that reads like a sentence a human can act on.
     */
    private function errorMessage(array $body, int $code): string
    {
        $err = $body['error'] ?? null;
        if (! is_array($err)) {
            return "HTTP {$code}";
        }

        $msg = trim((string) ($err['description'] ?? ''));
        if ($msg === '') {
            $msg = trim((string) ($err['code'] ?? "HTTP {$code}"));
        }

        // The offending field is often the whole story ("amount must be at
        // least 100"), and Razorpay reports it separately from the description.
        $field = trim((string) ($err['field'] ?? ''));
        if ($field !== '' && ! str_contains($msg, $field)) {
            $msg .= " ({$field})";
        }

        return $msg;
    }
}
