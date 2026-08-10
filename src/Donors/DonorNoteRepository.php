<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Time\Clock;
use Dono\Vendor\Queryable\DB;

/**
 * Repository for DonorNote. Encrypts/decrypts body at the boundary.
 *
 * @since 1.0.0
 */
final class DonorNoteRepository
{
    /** @since 1.0.0 */
    public function __construct(
        private Crypto $crypto,
        private Clock $clock,
    ) {
    }

    /**
     * Newest-first notes with decrypted body. Authorization is the caller's responsibility.
     *
     * @return array<array{
     *   id:int, donor_id:int, author_user_id:?int, body:string,
     *   created_at:string, updated_at:string
     * }>
     *
     * @since 1.0.0
     */
    public function listForDonor(int $donorId, int $limit = 50): array
    {
        $rows = DonorNote::query()
            ->where('donor_id', $donorId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->getAll();

        return array_map(fn (DonorNote $n) => $this->shape($n), $rows);
    }

    /** @since 1.0.0 */
    public function create(int $donorId, string $body, ?int $authorUserId): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $note = DonorNote::make();
        $note->donor_id       = $donorId;
        $note->author_user_id = $authorUserId;
        $note->body_encrypted = $this->crypto->encrypt($body);
        $note->created_at     = $now;
        $note->updated_at     = $now;
        $note->save();
        return $this->shape($note);
    }

    /** @since 1.0.0 */
    public function delete(int $noteId): bool
    {
        $note = DonorNote::query()->find('id', $noteId);
        if (! $note) return false;
        DB::table('dono_donor_notes')->where('id', $noteId)->delete();
        return true;
    }

    /** @since 1.0.0 */
    public function findById(int $id): ?DonorNote
    {
        return DonorNote::query()->find('id', $id);
    }

    /**
     * @return array{id:int,donor_id:int,author_user_id:?int,author_display_name:?string,author_role:?string,body:string,created_at:string,updated_at:string}
     *
     * @since 1.0.0
     */
    private function shape(DonorNote $n): array
    {
        [$displayName, $role] = $this->resolveAuthor($n->author_user_id !== null ? (int) $n->author_user_id : null);
        return [
            'id'                  => (int) $n->id,
            'donor_id'            => (int) $n->donor_id,
            'author_user_id'      => $n->author_user_id !== null ? (int) $n->author_user_id : null,
            'author_display_name' => $displayName,
            'author_role'         => $role,
            'body'                => $this->crypto->decrypt((string) $n->body_encrypted),
            'created_at'          => (string) $n->created_at,
            'updated_at'          => (string) $n->updated_at,
        ];
    }

    /**
     * @return array{0:?string,1:?string}
     *
     * @since 1.0.0
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
