<?php

declare(strict_types=1);

namespace Dono\Foundation\Crypto;

use Dono\Foundation\Config\SystemSetting;
use Dono\Vendor\Queryable\DB;
use RuntimeException;

/**
 * Authenticated AES-256-GCM encryption for PII columns.
 *
 * Key lives in dono_system_settings (encryption_key_v1), auto-generated on first use.
 * Key loss is unrecoverable: if the key is missing but encrypted records exist, the
 * admin UI is flagged and a fresh key is generated so new writes still work.
 *
 * @version 1.0.0
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private const SETTING_KEY           = 'encryption_key_v1';
    private const SETTING_KEY_LOST_FLAG = 'encryption_key_lost_at';

    private string $key;

    public function __construct()
    {
        $this->key = $this->loadKey();
    }

    /** Encrypt plaintext; returns base64-encoded IV + tag + ciphertext. */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Dono Crypto: encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    /** Null when payload is malformed or tampered. */
    public function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            return null;
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    /** Has the encryption key been lost on this install? */
    public static function keyLostAt(): ?string
    {
        $v = SystemSetting::read(self::SETTING_KEY_LOST_FLAG);
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Load the AES key from settings, generating one if absent. */
    private function loadKey(): string
    {
        $stored = SystemSetting::read(self::SETTING_KEY);
        if (is_string($stored) && $stored !== '') {
            $decoded = base64_decode($stored, true);
            if ($decoded !== false && strlen($decoded) === 32) return $decoded;
        }

        // No key but encrypted records exist: unrecoverable. Flag it and generate a fresh key.
        if ($this->encryptedRecordsExist()) {
            SystemSetting::write(self::SETTING_KEY_LOST_FLAG, gmdate('Y-m-d H:i:s'));
        }

        $key = random_bytes(32);
        SystemSetting::write(self::SETTING_KEY, base64_encode($key));
        return $key;
    }

    /** Return whether any donor row has a non-empty email_encrypted. */
    private function encryptedRecordsExist(): bool
    {
        $result = DB::raw(
            'SELECT 1 FROM ' . DB::getPrefix() . "dono_donors
             WHERE email_encrypted IS NOT NULL AND email_encrypted != ''
             LIMIT 1"
        );
        return ! empty($result['rows']);
    }
}
