<?php

declare(strict_types=1);

namespace Gratora\Donors\Portal;

use Gratora\Foundation\Crypto\Crypto;

/**
 * The args a queued sign-in job carries.
 *
 * Action Scheduler stores them as JSON in a table nothing erases, and keeps
 * them for a month after the job has run, so the address travels sealed. Shared
 * rather than private to the portal controller: an add-on that queues the same
 * hook would otherwise put the address there in the clear.
 *
 * @since 1.0.0
 */
final class MagicLinkJob
{
    /** @return array{payload:string} */
    public static function seal(Crypto $crypto, string $email, ?string $first = null, ?string $last = null): array
    {
        return [
            'payload' => $crypto->encrypt((string) wp_json_encode([
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
            ])),
        ];
    }

    /**
     * The cleartext shape is still read: jobs queued before an upgrade are
     * already in the table, and dropping them would lose sign-in mails nobody
     * could see had gone missing.
     *
     * @param  array{payload?:string, email?:string, first_name?:?string, last_name?:?string}|string $args
     * @return array{email:string, first_name:?string, last_name:?string}
     */
    public static function open(Crypto $crypto, mixed $args): array
    {
        $sealed  = is_array($args) ? (string) ($args['payload'] ?? '') : (string) $args;
        $decoded = $sealed !== '' ? json_decode((string) $crypto->decrypt($sealed), true) : null;

        if (is_array($decoded)) {
            return [
                'email'      => (string) ($decoded['email'] ?? ''),
                'first_name' => $decoded['first_name'] ?? null,
                'last_name'  => $decoded['last_name'] ?? null,
            ];
        }

        if (is_array($args)) {
            return [
                'email'      => (string) ($args['email'] ?? ''),
                'first_name' => $args['first_name'] ?? null,
                'last_name'  => $args['last_name'] ?? null,
            ];
        }

        return ['email' => (string) $args, 'first_name' => null, 'last_name' => null];
    }
}
