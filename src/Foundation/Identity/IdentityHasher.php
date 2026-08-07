<?php

declare(strict_types=1);

namespace Dono\Foundation\Identity;

use Dono\Async\AsyncDispatcher;
use Dono\Donors\Donor;
use Dono\Donors\DonorEmailRehasher;
use Dono\Foundation\Config\SystemSetting;

/**
 * Hash helpers for indexed lookup of sensitive identifiers.
 *
 * email_hash: HMAC-SHA-256 with a per-install pepper (email_pepper_v1).
 * Pepper loss while donor rows exist triggers a background rehash; dedup self-recovers.
 * ip_hash: per-install salt (ip_salt_v1); salt loss is harmless.
 * userAgentHash: unsalted.
 *
 * @version 1.0.0
 */
final class IdentityHasher
{
    private const SETTING_EMAIL_PEPPER = 'email_pepper_v1';
    private const SETTING_IP_SALT      = 'ip_salt_v1';

    private string $ipSalt;
    private string $emailPepper;

    public function __construct(private AsyncDispatcher $async)
    {
        $this->ipSalt      = $this->loadIpSalt();
        $this->emailPepper = $this->loadEmailPepper();
    }

    /** Return HMAC-SHA-256 of the normalised email, keyed by the install pepper. */
    public function emailHash(string $email): string
    {
        return hash_hmac('sha256', $this->normalizeEmail($email), $this->emailPepper);
    }

    /** Lowercase and trim an email address. */
    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * The mailbox an address actually reaches, for rate limiting only.
     *
     * "a+1@gmail.com", "a+2@gmail.com" and "a.a@gmail.com" are three different
     * addresses and three different donors, but one inbox. A per-address send
     * limit therefore does not limit sends to a person, and anything that mails
     * on demand becomes a way to flood somebody else's mail.
     *
     * Never use this for identity. emailHash() is the UNIQUE key on the donor
     * table and the handle erasure severs, and collapsing distinct addresses
     * into one donor would merge two people's giving history.
     */
    public function rateLimitMailbox(string $email): string
    {
        $email = $this->normalizeEmail($email);
        $at    = strrpos($email, '@');
        if ($at === false) return $email;

        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        $plus = strpos($local, '+');
        if ($plus !== false) $local = substr($local, 0, $plus);

        // Dots are only insignificant on some providers, so this is not applied
        // everywhere: elsewhere the two addresses can be different mailboxes.
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local  = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    public function ipHash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') return null;
        return hash('sha256', $ip . '|' . $this->ipSalt);
    }

    public function userAgentHash(?string $ua): ?string
    {
        if ($ua === null || $ua === '') return null;
        return hash('sha256', $ua);
    }

    /** Load or generate the email pepper, enqueuing a rehash if donors exist. */
    private function loadEmailPepper(): string
    {
        $stored = SystemSetting::read(self::SETTING_EMAIL_PEPPER);
        if (is_string($stored) && $stored !== '') return $stored;

        $pepper = bin2hex(random_bytes(32));
        SystemSetting::write(self::SETTING_EMAIL_PEPPER, $pepper);

        if ($this->donorsExist()) {
            // Flag first, then try. This runs at plugins_loaded on a fresh
            // install, before Action Scheduler's data store exists, so the
            // enqueue silently does nothing; the flag is what gets it picked up
            // on init instead of losing the rehash for good.
            DonorEmailRehasher::markPending();
            $this->async->enqueue(DonorEmailRehasher::HOOK, []);
        }

        return $pepper;
    }

    /** Load or generate the IP salt. */
    private function loadIpSalt(): string
    {
        $stored = SystemSetting::read(self::SETTING_IP_SALT);
        if (is_string($stored) && $stored !== '') return $stored;

        $salt = bin2hex(random_bytes(32));
        SystemSetting::write(self::SETTING_IP_SALT, $salt);
        return $salt;
    }

    private function donorsExist(): bool
    {
        return Donor::query()->exists();
    }
}
