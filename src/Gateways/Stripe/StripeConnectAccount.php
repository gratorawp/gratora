<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Crypto\Crypto;

/**
 * Persists and retrieves Stripe Connect account tokens and capability flags.
 *
 * @version 1.0.0
 */
final class StripeConnectAccount implements ConnectAccountResolver
{
    private const KEY = 'stripe_connect_account';

    /**
     * Per-operation test/live selector. Set by the gateway from the donation's
     * is_test (or the form's TestMode) before any token-bearing Stripe call.
     * Null until a caller sets it; isTestMode() then fails safe to test.
     */
    private ?bool $testOverride = null;

    public function __construct(private Crypto $crypto)
    {
    }

    /**
     * @return array{
     *   account_id:string, charges_enabled:bool, payouts_enabled:bool,
     *   details_submitted:bool, email:string, business_name:string,
     *   country:string, connected_at:string, has_live:bool, has_test:bool
     * }|null
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
            'has_live'          => ! empty($data['access_token_live']),
            'has_test'          => ! empty($data['access_token_test']),
        ];
    }

    /** Returns the stored connected account id, or null if not connected. */
    public function accountId(): ?string
    {
        $data = $this->raw();
        $id = $data['account_id'] ?? '';
        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Resolve which connected account a donation settles to (multi-account seam). */
    public function accountIdFor(?int $campaignId = null, ?int $formId = null): ?string
    {
        $resolver = apply_filters('dono.stripe.account_resolver', $this);
        $id = $resolver instanceof ConnectAccountResolver
            ? $resolver->resolve($campaignId, $formId)
            : $this->resolveDefault($campaignId, $formId);

        return apply_filters('dono.stripe.account_id_for', $id, $campaignId, $formId);
    }

    public function resolve(?int $campaignId, ?int $formId): ?string
    {
        return $this->resolveDefault($campaignId, $formId);
    }

    private function resolveDefault(?int $campaignId, ?int $formId): ?string
    {
        return $this->accountId();
    }

    /** True when a connected account id is stored. */
    public function isConnected(): bool
    {
        return $this->accountId() !== null;
    }

    /** Stripe will accept live/test charges only when charges are enabled. */
    public function canCharge(): bool
    {
        $data = $this->raw();
        return $data !== null && ! empty($data['charges_enabled']);
    }

    /**
     * Select test vs live for the next token-bearing operation. Derived from
     * the donation's is_test (or TestMode::forForm() for form-context work),
     * never from a standalone gateway setting.
     */
    public function useTestMode(bool $test): void
    {
        $this->testOverride = $test;
    }

    /** Returns the active test/live mode. Fails safe to test if unset. */
    public function isTestMode(): bool
    {
        // Fail safe: if no caller set the mode, assume test so a live charge
        // can never fire by accident. Every real charge path sets it.
        return $this->testOverride ?? true;
    }

    /** Decrypted access token for the active mode (test vs live). */
    public function activeAccessToken(): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        $field = $this->isTestMode() ? 'access_token_test' : 'access_token_live';
        $enc = (string) ($data[$field] ?? '');
        if ($enc === '') return '';
        $plain = $this->crypto->decrypt($enc);
        return is_string($plain) ? $plain : '';
    }

    /** Returns the publishable key for the active mode. */
    public function activePublishableKey(): string
    {
        return $this->publishableKeyFor($this->isTestMode());
    }

    /** Returns the publishable key for an explicit mode. */
    public function publishableKeyFor(bool $test): string
    {
        $data = $this->raw();
        if ($data === null) return '';
        $field = $test ? 'publishable_key_test' : 'publishable_key_live';
        return (string) ($data[$field] ?? '');
    }

    /**
     * Persist the tokens the broker handed back. Access tokens are encrypted at rest.
     *
     * @param array{
     *   stripe_user_id?:string,
     *   stripe_access_token?:string, stripe_access_token_test?:string,
     *   stripe_publishable_key?:string, stripe_publishable_key_test?:string
     * } $payload
     * @param array<string,mixed> $accountObject Optional account retrieve for flags.
     */
    public function store(array $payload, array $accountObject = []): void
    {
        $accountId = (string) ($payload['stripe_user_id'] ?? '');
        if ($accountId === '') return;

        $liveTok = (string) ($payload['stripe_access_token'] ?? '');
        $testTok = (string) ($payload['stripe_access_token_test'] ?? '');

        SystemSetting::write(self::KEY, (string) wp_json_encode([
            'account_id'            => $accountId,
            'access_token_live'     => $liveTok !== '' ? $this->crypto->encrypt($liveTok) : '',
            'access_token_test'     => $testTok !== '' ? $this->crypto->encrypt($testTok) : '',
            'publishable_key_live'  => (string) ($payload['stripe_publishable_key'] ?? ''),
            'publishable_key_test'  => (string) ($payload['stripe_publishable_key_test'] ?? ''),
            'charges_enabled'       => (bool)   ($accountObject['charges_enabled'] ?? false),
            'payouts_enabled'       => (bool)   ($accountObject['payouts_enabled'] ?? false),
            'details_submitted'     => (bool)   ($accountObject['details_submitted'] ?? false),
            'email'                 => (string) ($accountObject['email'] ?? ''),
            'business_name'         => (string) ($accountObject['business_profile']['name'] ?? ''),
            'country'               => (string) ($accountObject['country'] ?? ''),
            'connected_at'          => gmdate('Y-m-d H:i:s'),
        ]));
    }

    /** Merge capability flags from an account.updated webhook / retrieve. */
    public function refresh(array $accountObject): void
    {
        $data = $this->raw();
        if ($data === null) return;

        $data['charges_enabled']   = (bool)   ($accountObject['charges_enabled'] ?? $data['charges_enabled'] ?? false);
        $data['payouts_enabled']   = (bool)   ($accountObject['payouts_enabled'] ?? $data['payouts_enabled'] ?? false);
        $data['details_submitted'] = (bool)   ($accountObject['details_submitted'] ?? $data['details_submitted'] ?? false);
        $data['email']             = (string) ($accountObject['email'] ?? $data['email'] ?? '');
        $data['business_name']     = (string) ($accountObject['business_profile']['name'] ?? $data['business_name'] ?? '');
        $data['country']           = (string) ($accountObject['country'] ?? $data['country'] ?? '');

        SystemSetting::write(self::KEY, (string) wp_json_encode($data));
    }

    /** Remove the stored account data. */
    public function forget(): void
    {
        SystemSetting::forget(self::KEY);
    }

    /** @return array<string,mixed>|null */
    private function raw(): ?array
    {
        $json = SystemSetting::read(self::KEY);
        if (! is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['account_id'])) return null;
        return $data;
    }
}
