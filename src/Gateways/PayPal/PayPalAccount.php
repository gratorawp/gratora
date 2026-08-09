<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Crypto\Crypto;

/**
 * The organization's own PayPal REST app credentials, stored per mode.
 *
 * PayPal's client id is public (it goes in the JS SDK URL, like a Stripe
 * publishable key); the secret is private and encrypted at rest. The webhook id
 * is stored alongside because PayPal's signature verification requires it.
 *
 * Sandbox and live are entirely separate PayPal apps, so each mode holds its
 * own triple.
 *
 * @version 1.0.0
 */
final class PayPalAccount
{
    private const KEY = 'paypal_account';

    /**
     * Per-operation test/live selector, set from the donation's is_test before
     * any credential-bearing call. Null until a caller sets it; isTestMode()
     * then fails safe to test.
     */
    private ?bool $testOverride = null;

    public function __construct(private Crypto $crypto)
    {
    }

    /** Persist one mode's credentials. Modes are independent. */
    public function saveKeys(bool $test, string $clientId, string $secret): void
    {
        $data = $this->raw() ?? [];

        $data[$test ? 'client_id_test' : 'client_id_live'] = $clientId;
        $data[$test ? 'secret_test' : 'secret_live'] = $secret === ''
            ? ''
            : $this->crypto->encrypt($secret);
        $data['connected_at'] = $data['connected_at'] ?? gmdate('Y-m-d H:i:s');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
        $this->forgetToken($test);
    }

    /** The webhook id PayPal assigns to the endpoint, needed to verify signatures. */
    public function saveWebhookId(bool $test, string $webhookId): void
    {
        $data = $this->raw() ?? [];
        $data[$test ? 'webhook_id_test' : 'webhook_id_live'] = $webhookId;
        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    public function webhookId(bool $test): string
    {
        $data = $this->raw();
        return $data === null ? '' : (string) ($data[$test ? 'webhook_id_test' : 'webhook_id_live'] ?? '');
    }

    /**
     * Screen-facing shape. The secret is never returned, only a last-4 hint.
     *
     * @return array{
     *   email:string, merchant_id:string, connected_at:string,
     *   has_test:bool, has_live:bool,
     *   client_id_test:string, client_id_live:string,
     *   secret_hint_test:string, secret_hint_live:string,
     *   webhook_test:bool, webhook_live:bool
     * }|null
     */
    public function get(): ?array
    {
        $data = $this->raw();
        if ($data === null) return null;

        return [
            'email'            => (string) ($data['email'] ?? ''),
            'merchant_id'      => (string) ($data['merchant_id'] ?? ''),
            'connected_at'     => (string) ($data['connected_at'] ?? ''),
            'has_test'         => $this->hasKeysFor(true),
            'has_live'         => $this->hasKeysFor(false),
            'client_id_test'   => (string) ($data['client_id_test'] ?? ''),
            'client_id_live'   => (string) ($data['client_id_live'] ?? ''),
            'secret_hint_test' => $this->hint(true),
            'secret_hint_live' => $this->hint(false),
            'webhook_test'     => $this->webhookId(true) !== '',
            'webhook_live'     => $this->webhookId(false) !== '',
        ];
    }

    /** True when either mode has credentials: the gateway registers on this. */
    public function isConnected(): bool
    {
        return $this->hasKeysFor(true) || $this->hasKeysFor(false);
    }

    public function hasKeysFor(bool $test): bool
    {
        $data = $this->raw();
        if ($data === null) return false;
        return ($data[$test ? 'client_id_test' : 'client_id_live'] ?? '') !== ''
            && ($data[$test ? 'secret_test' : 'secret_live'] ?? '') !== '';
    }

    /**
     * PayPal has no "charges enabled" flag equivalent to Stripe's: a REST app
     * with working credentials can take payments, and credentials are verified
     * at save time, so having them is the readiness signal.
     *
     * Deliberately mode-independent. This answers "may the form offer PayPal",
     * which is asked before any donation has fixed a mode, and the mode
     * override is per-operation state on a shared instance: keying off it made
     * a live-mode call earlier in the request hide PayPal from a sandbox form.
     * A charge in a mode with no credentials still fails closed in PayPalApi.
     */
    public function canCharge(): bool
    {
        return $this->isConnected();
    }

    public function useTestMode(bool $test): void
    {
        $this->testOverride = $test;
    }

    /** Fails safe to test (sandbox) when no caller set the mode. */
    public function isTestMode(): bool
    {
        return $this->testOverride ?? true;
    }

    public function activeClientId(): string
    {
        return $this->clientIdFor($this->isTestMode());
    }

    public function clientIdFor(bool $test): string
    {
        $data = $this->raw();
        return $data === null ? '' : (string) ($data[$test ? 'client_id_test' : 'client_id_live'] ?? '');
    }

    public function activeSecret(): string
    {
        return $this->secretFor($this->isTestMode());
    }

    public function secretFor(bool $test): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        $enc = (string) ($data[$test ? 'secret_test' : 'secret_live'] ?? '');
        if ($enc === '') return '';
        $plain = $this->crypto->decrypt($enc);
        return is_string($plain) ? $plain : '';
    }

    /** Merge merchant details learned from the credential check. */
    public function refresh(array $identity): void
    {
        $data = $this->raw();
        if ($data === null) return;

        $data['email']       = (string) ($identity['email'] ?? $data['email'] ?? '');
        $data['merchant_id'] = (string) ($identity['payer_id'] ?? $identity['merchant_id'] ?? $data['merchant_id'] ?? '');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /**
     * OAuth2 access tokens are short-lived; cache per mode so every API call
     * does not pay for a token round-trip. Stored in a transient, not the
     * settings blob, because it is disposable.
     */
    public function cachedToken(bool $test): string
    {
        $t = get_transient($this->tokenKey($test));
        return is_string($t) ? $t : '';
    }

    public function cacheToken(bool $test, string $token, int $expiresIn): void
    {
        // Expire a minute early so a token never dies mid-request.
        set_transient($this->tokenKey($test), $token, max(60, $expiresIn - 60));
    }

    public function forgetToken(bool $test): void
    {
        delete_transient($this->tokenKey($test));
    }

    /** Remove one mode's credentials, leaving the other intact. */
    public function forgetMode(bool $test): void
    {
        $data = $this->raw();
        if ($data === null) return;

        foreach ([
            $test ? 'client_id_test' : 'client_id_live',
            $test ? 'secret_test' : 'secret_live',
            $test ? 'webhook_id_test' : 'webhook_id_live',
        ] as $field) {
            $data[$field] = '';
        }
        $this->forgetToken($test);

        if (($data['secret_test'] ?? '') === '' && ($data['secret_live'] ?? '') === '') {
            SystemSetting::forget(self::KEY);
            return;
        }
        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    public function forget(): void
    {
        $this->forgetToken(true);
        $this->forgetToken(false);
        SystemSetting::forget(self::KEY);
    }

    private function tokenKey(bool $test): string
    {
        return 'dono_paypal_token_' . ($test ? 'test' : 'live');
    }

    private function hint(bool $test): string
    {
        $s = $this->secretFor($test);
        return $s === '' ? '' : substr($s, -4);
    }

    /** @return array<string,mixed>|null */
    private function raw(): ?array
    {
        $json = SystemSetting::read(self::KEY);
        if (! is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
