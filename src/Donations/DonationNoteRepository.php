<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Time\Clock;
use Dono\Vendor\Queryable\DB;

/**
 * Encrypt/decrypt seam for donation-scoped admin notes.
 *
 * @version 1.0.0
 */
final class DonationNoteRepository
{
    public function __construct(
        private Crypto $crypto,
        private Clock $clock,
    ) {
    }

    /** @return array<array{id:int,donation_id:int,author_user_id:?int,body:string,created_at:string,updated_at:string}> */
    public function listForDonation(int $donationId, int $limit = 50): array
    {
        $rows = DonationNote::query()
            ->where('donation_id', $donationId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->getAll();

        return array_map(fn (DonationNote $n) => $this->shape($n), $rows);
    }

    public function create(int $donationId, string $body, ?int $authorUserId): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $note = DonationNote::make();
        $note->donation_id    = $donationId;
        $note->author_user_id = $authorUserId;
        $note->body_encrypted = $this->crypto->encrypt($body);
        $note->created_at     = $now;
        $note->updated_at     = $now;
        $note->save();
        return $this->shape($note);
    }

    public function delete(int $noteId): bool
    {
        $note = DonationNote::query()->find('id', $noteId);
        if (! $note) return false;
        DB::table('dono_donation_notes')->where('id', $noteId)->delete();
        return true;
    }

    public function findById(int $id): ?DonationNote
    {
        return DonationNote::query()->find('id', $id);
    }

    /** @return array{id:int,donation_id:int,author_user_id:?int,author_display_name:?string,author_role:?string,body:string,created_at:string,updated_at:string} */
    private function shape(DonationNote $n): array
    {
        [$displayName, $role] = $this->resolveAuthor($n->author_user_id !== null ? (int) $n->author_user_id : null);
        return [
            'id'                  => (int) $n->id,
            'donation_id'         => (int) $n->donation_id,
            'author_user_id'      => $n->author_user_id !== null ? (int) $n->author_user_id : null,
            'author_display_name' => $displayName,
            'author_role'         => $role,
            'body'                => $this->crypto->decrypt((string) $n->body_encrypted),
            'created_at'          => (string) $n->created_at,
            'updated_at'          => (string) $n->updated_at,
        ];
    }

    /**
     * Resolve a WP user id to display name + primary role. Statically cached
     * so a list of notes hits get_userdata() once per author, not per row.
     *
     * @return array{0:?string,1:?string}
     */
    private function resolveAuthor(?int $userId): array
    {
        static $cache = [];
        if ($userId === null) return [null, null];
        if (isset($cache[$userId])) return $cache[$userId];

        $user = get_userdata($userId);
        if (! $user) return $cache[$userId] = [null, null];

        $name = $user->display_name ?: $user->user_login;
        $role = is_array($user->roles) && $user->roles ? (string) $user->roles[0] : null;
        return $cache[$userId] = [$name, $role];
    }
}
