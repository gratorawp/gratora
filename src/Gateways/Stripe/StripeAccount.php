<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Crypto\Crypto;

/**
 * The organization's own Stripe API keys, stored per mode.
 *
 * Dono is not a Stripe platform: the org pastes the keys from their own Stripe
 * dashboard and every call is made directly as that account. Secret keys are
 * encrypted at rest and never leave the server; publishable keys are public by
 * design and are handed to the browser to mount the Payment Element.
 *
 * @since 1.0.0
 */
final class StripeAccount
{
    private const KEY = 'stripe_account';

    /**
     * Per-operation test/live selector. Set by the gateway from the donation's
     * is_test (or the form's TestMode) before any key-bearing Stripe call.
     * Null until a caller sets it; isTestMode() then fails safe to test.
     */
    private ?bool $testOverride = null;

    /** @since 1.0.0 */
    public function __construct(private Crypto $crypto)
    {
    }

    /**
     * Persist one mode's key pair. Modes are independent, so saving live keys
     * never disturbs a working test connection.
     *
     * @since 1.0.0
     */
    public function saveKeys(bool $test, string $secret, string $publishable): void
    {
        $data = $this->raw() ?? [];

        $data[$test ? 'secret_test' : 'secret_live'] = $secret === ''
            ? ''
            : $this->crypto->encrypt($secret);
        $data[$test ? 'publishable_test' : 'publishable_live'] = $publishable;
        $data['connected_at'] = $data['connected_at'] ?? gmdate('Y-m-d H:i:s');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /**
     * Screen-facing shape. Secret keys are never returned, only a last-4 hint
     * so an admin can tell which key is stored without exposing it.
     *
     * @return array{
     *   account_id:string, charges_enabled:bool, payouts_enabled:bool,
     *   details_submitted:bool, email:string, business_name:string,
     *   country:string, connected_at:string, has_live:bool, has_test:bool,
     *   secret_hint_test:string, secret_hint_live:string,
     *   publishable_test:string, publishable_live:string
     * }|null
     *
     * @since 1.0.0
     */
    public function get(): ?array
    {
        $data = $this->raw();
        if ($data === null) return null;

        return [
            'account_id'        => (string) ($data['account_id'] ?? ''),
            'charges_enabled'   => (bool)   ($data['charges_enabled'] ?? false),
            'payouts_enabled'   => (bool)   ($data['payouts_enabled'] ?? false),
            'details_submitted' => (bool)   ($data['details_submitted'] ?? false),
            'email'             => (string) ($data['email'] ?? ''),
            'business_name'     => (string) ($data['business_name'] ?? ''),
            'country'           => (string) ($data['country'] ?? ''),
            'connected_at'      => (string) ($data['connected_at'] ?? ''),
            'has_live'          => $this->hasKeysFor(false),
            'has_test'          => $this->hasKeysFor(true),
            'secret_hint_test'  => $this->hint(true),
            'secret_hint_live'  => $this->hint(false),
            'publishable_test'  => (string) ($data['publishable_test'] ?? ''),
            'publishable_live'  => (string) ($data['publishable_live'] ?? ''),
        ];
    }

    /**
     * Stripe account id, learned from the key verification retrieve.
     *
     * @since 1.0.0
     */
    public function accountId(): ?string
    {
        $data = $this->raw();
        $id = $data['account_id'] ?? '';
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * True when at least one mode has a secret key. The gateway registers on
     * this; each individual charge still fails closed via StripeApi when the
     * active mode has no key.
     *
     * @since 1.0.0
     */
    public function isConnected(): bool
    {
        return $this->hasKeysFor(true) || $this->hasKeysFor(false);
    }

    /** @since 1.0.0 */
    public function hasKeysFor(bool $test): bool
    {
        $data = $this->raw();
        return $data !== null && ($data[$test ? 'secret_test' : 'secret_live'] ?? '') !== '';
    }

    /**
     * Stripe accepts charges only once the account is verified for it.
     *
     * @since 1.0.0
     */
    public function canCharge(): bool
    {
        $data = $this->raw();
        return $data !== null && ! empty($data['charges_enabled']);
    }

    /**
     * Select test vs live for the next key-bearing operation. Derived from the
     * donation's is_test (or TestMode::forForm() for form-context work), never
     * from a standalone gateway setting.
     *
     * @since 1.0.0
     */
    public function useTestMode(bool $test): void
    {
        $this->testOverride = $test;
    }

    /** @since 1.0.0 */
    public function isTestMode(): bool
    {
        // Fail safe: if no caller set the mode, assume test so a live charge
        // can never fire by accident. Every real charge path sets it.
        return $this->testOverride ?? true;
    }

    /**
     * Decrypted secret key for the active mode.
     *
     * @since 1.0.0
     */
    public function activeSecretKey(): string
    {
        return $this->secretKeyFor($this->isTestMode());
    }

    /**
     * Decrypted secret key for an explicit mode.
     *
     * @since 1.0.0
     */
    public function secretKeyFor(bool $test): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        $enc = (string) ($data[$test ? 'secret_test' : 'secret_live'] ?? '');
        if ($enc === '') return '';
        $plain = $this->crypto->decrypt($enc);
        return is_string($plain) ? $plain : '';
    }

    /** @since 1.0.0 */
    public function activePublishableKey(): string
    {
        return $this->publishableKeyFor($this->isTestMode());
    }

    /** @since 1.0.0 */
    public function publishableKeyFor(bool $test): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        return (string) ($data[$test ? 'publishable_test' : 'publishable_live'] ?? '');
    }

    /**
     * Merge capability flags from an account retrieve / account.updated webhook.
     *
     * @since 1.0.0
     */
    public function refresh(array $accountObject): void
    {
        $data = $this->raw();
        if ($data === null) return;

        $id = (string) ($accountObject['id'] ?? '');
        if ($id !== '') {
            $data['account_id'] = $id;
        }
        $data['charges_enabled']   = (bool)   ($accountObject['charges_enabled'] ?? $data['charges_enabled'] ?? false);
        $data['payouts_enabled']   = (bool)   ($accountObject['payouts_enabled'] ?? $data['payouts_enabled'] ?? false);
        $data['details_submitted'] = (bool)   ($accountObject['details_submitted'] ?? $data['details_submitted'] ?? false);
        $data['email']             = (string) ($accountObject['email'] ?? $data['email'] ?? '');
        $data['business_name']     = (string) ($accountObject['business_profile']['name'] ?? $data['business_name'] ?? '');
        $data['country']           = (string) ($accountObject['country'] ?? $data['country'] ?? '');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /**
     * Remove one mode's keys, leaving the other mode intact.
     *
     * @since 1.0.0
     */
    public function forgetMode(bool $test): void
    {
        $data = $this->raw();
        if ($data === null) return;

        $data[$test ? 'secret_test' : 'secret_live'] = '';
        $data[$test ? 'publishable_test' : 'publishable_live'] = '';

        if (($data['secret_test'] ?? '') === '' && ($data['secret_live'] ?? '') === '') {
            SystemSetting::forget(self::KEY);
            return;
        }
        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /** @since 1.0.0 */
    public function forget(): void
    {
        SystemSetting::forget(self::KEY);
    }

    /**
     * Last 4 of a stored secret key, for "which key is this?" without exposing it.
     *
     * @since 1.0.0
     */
    private function hint(bool $test): string
    {
        $key = $this->secretKeyFor($test);
        return $key === '' ? '' : substr($key, -4);
    }

    /**
     * @return array<string,mixed>|null
     *
     * @since 1.0.0
     */
    private function raw(): ?array
    {
        $json = SystemSetting::read(self::KEY);
        if (! is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
