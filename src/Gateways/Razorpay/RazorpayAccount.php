<?php

declare(strict_types=1);

namespace Dono\Gateways\Razorpay;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Crypto\Crypto;

/**
 * The organisation's own Razorpay API keys, stored per mode.
 *
 * The key id is public: it goes into the Checkout script in the browser, like a
 * Stripe publishable key. The key secret and the webhook secret are private and
 * encrypted at rest.
 *
 * Unlike PayPal, test and live are the same API host; the key prefix
 * (rzp_test_ / rzp_live_) is what selects the environment, so a key pasted into
 * the wrong slot silently charges in the wrong mode. That is checked at save
 * time rather than trusted.
 *
 * @version 1.0.0
 */
final class RazorpayAccount
{
    private const KEY = 'razorpay_account';

    /**
     * Per-operation test/live selector, set from the donation's is_test before
     * any credential-bearing call. Null until a caller sets it; isTestMode()
     * then fails safe to test.
     */
    private ?bool $testOverride = null;

    public function __construct(private Crypto $crypto)
    {
    }

    /** Persist one mode's key pair. Modes are independent. */
    public function saveKeys(bool $test, string $keyId, string $keySecret): void
    {
        $data = $this->raw() ?? [];

        $data[$test ? 'key_id_test' : 'key_id_live'] = $keyId;
        $data[$test ? 'key_secret_test' : 'key_secret_live'] = $keySecret === ''
            ? ''
            : $this->crypto->encrypt($keySecret);
        $data['connected_at'] = $data['connected_at'] ?? gmdate('Y-m-d H:i:s');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /**
     * The secret Razorpay signs webhook deliveries with. Chosen by the admin
     * when they create the webhook in the Razorpay dashboard, so there is
     * nothing to provision automatically.
     */
    public function saveWebhookSecret(bool $test, string $secret): void
    {
        $data = $this->raw() ?? [];
        $data[$test ? 'webhook_secret_test' : 'webhook_secret_live'] = $secret === ''
            ? ''
            : $this->crypto->encrypt($secret);
        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    public function webhookSecret(bool $test): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        $enc = (string) ($data[$test ? 'webhook_secret_test' : 'webhook_secret_live'] ?? '');
        if ($enc === '') return '';
        $plain = $this->crypto->decrypt($enc);
        return is_string($plain) ? $plain : '';
    }

    /**
     * Screen-facing shape. Neither secret is ever returned, only a last-4 hint.
     *
     * @return array{
     *   merchant_id:string, connected_at:string,
     *   has_test:bool, has_live:bool,
     *   key_id_test:string, key_id_live:string,
     *   secret_hint_test:string, secret_hint_live:string,
     *   webhook_test:bool, webhook_live:bool
     * }|null
     */
    public function get(): ?array
    {
        $data = $this->raw();
        if ($data === null) return null;

        return [
            'merchant_id'      => (string) ($data['merchant_id'] ?? ''),
            'connected_at'     => (string) ($data['connected_at'] ?? ''),
            'has_test'         => $this->hasKeysFor(true),
            'has_live'         => $this->hasKeysFor(false),
            'key_id_test'      => (string) ($data['key_id_test'] ?? ''),
            'key_id_live'      => (string) ($data['key_id_live'] ?? ''),
            'secret_hint_test' => $this->hint(true),
            'secret_hint_live' => $this->hint(false),
            'webhook_test'     => $this->webhookSecret(true) !== '',
            'webhook_live'     => $this->webhookSecret(false) !== '',
        ];
    }

    /** True when either mode has keys: the gateway registers on this. */
    public function isConnected(): bool
    {
        return $this->hasKeysFor(true) || $this->hasKeysFor(false);
    }

    public function hasKeysFor(bool $test): bool
    {
        $data = $this->raw();
        if ($data === null) return false;
        return ($data[$test ? 'key_id_test' : 'key_id_live'] ?? '') !== ''
            && ($data[$test ? 'key_secret_test' : 'key_secret_live'] ?? '') !== '';
    }

    /**
     * Razorpay has no "charges enabled" flag: working keys can take payments,
     * and keys are verified at save time, so having them is the readiness
     * signal.
     *
     * Deliberately mode-independent, for the same reason as PayPal: this answers
     * "may the form offer Razorpay", asked before any donation has fixed a mode,
     * while the mode override is per-operation state on a shared instance. A
     * charge in a mode with no keys still fails closed in RazorpayApi.
     */
    public function canCharge(): bool
    {
        return $this->isConnected();
    }

    public function useTestMode(bool $test): void
    {
        $this->testOverride = $test;
    }

    /** Fails safe to test when no caller set the mode. */
    public function isTestMode(): bool
    {
        return $this->testOverride ?? true;
    }

    public function activeKeyId(): string
    {
        return $this->keyIdFor($this->isTestMode());
    }

    public function keyIdFor(bool $test): string
    {
        $data = $this->raw();
        return $data === null ? '' : (string) ($data[$test ? 'key_id_test' : 'key_id_live'] ?? '');
    }

    public function activeKeySecret(): string
    {
        return $this->keySecretFor($this->isTestMode());
    }

    public function keySecretFor(bool $test): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        $enc = (string) ($data[$test ? 'key_secret_test' : 'key_secret_live'] ?? '');
        if ($enc === '') return '';
        $plain = $this->crypto->decrypt($enc);
        return is_string($plain) ? $plain : '';
    }

    /** Merge merchant details learned from the credential check. */
    public function refresh(array $identity): void
    {
        $data = $this->raw();
        if ($data === null) return;

        $data['merchant_id'] = (string) ($identity['merchant_id'] ?? $data['merchant_id'] ?? '');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /** Remove one mode's credentials, leaving the other intact. */
    public function forgetMode(bool $test): void
    {
        $data = $this->raw();
        if ($data === null) return;

        foreach ([
            $test ? 'key_id_test' : 'key_id_live',
            $test ? 'key_secret_test' : 'key_secret_live',
            $test ? 'webhook_secret_test' : 'webhook_secret_live',
        ] as $field) {
            $data[$field] = '';
        }

        if (($data['key_secret_test'] ?? '') === '' && ($data['key_secret_live'] ?? '') === '') {
            SystemSetting::forget(self::KEY);
            return;
        }
        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    public function forget(): void
    {
        SystemSetting::forget(self::KEY);
    }

    private function hint(bool $test): string
    {
        $s = $this->keySecretFor($test);
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
