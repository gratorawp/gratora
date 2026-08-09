<?php

declare(strict_types=1);

namespace Dono\Donors\Portal;

use Dono\Donors\DonorRepository;
use Dono\Donors\MagicLinkService;
use Dono\Donors\SignupRedemption;

final class PortalSession
{
    private const COOKIE = 'dono_donor_session';

    /** Idle window. Sliding: the clock that kills a session left open on a borrowed device. */
    private const IDLE_SECONDS = 1_800;

    /** Absolute cap from sign-in, regardless of activity. */
    private const MAX_SECONDS = 604_800;

    /** Sliding refresh is a write, so it happens at most this often. */
    private const REFRESH_AFTER = 300;

    /** Oldest sessions beyond this are revoked when a donor signs in again. */
    private const MAX_PER_DONOR = 5;

    public function __construct(
        private MagicLinkService $magicLinks,
        private DonorRepository $donors,
        private SignupRedemption $signups,
    ) {
    }

    /**
     * A sign-in link names a donor who already exists. A signup link names none:
     * whoever opens it controls the mailbox, which is the only evidence the
     * address was ever theirs.
     */
    public function startFromToken(string $rawToken): ?array
    {
        $token = $this->magicLinks->consumeAndValidate($rawToken, 'donor_portal');
        if ($token) {
            return $this->open((int) $token->donor_id);
        }

        return $this->startFromSignupToken($rawToken);
    }

    private function startFromSignupToken(string $rawToken): ?array
    {
        $donorId = $this->signups->redeem($rawToken);

        return $donorId > 0 ? $this->open($donorId) : null;
    }

    public function open(int $donorId): array
    {
        $sid  = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now  = time();

        set_transient(self::transientKey($sid), [
            'donor_id' => $donorId,
            'csrf'     => $csrf,
            'started'  => $now,
            'seen'     => $now,
        ], self::IDLE_SECONDS);

        $this->track($donorId, $sid);
        $this->setCookie($sid);

        return ['donor_id' => $donorId, 'csrf' => $csrf];
    }

    public function currentDonorId(): ?int
    {
        return $this->readSession()['donor_id'] ?? null;
    }

    public function csrfToken(): ?string
    {
        return $this->readSession()['csrf'] ?? null;
    }

    /** Seconds since this session's magic link was redeemed, for step-up checks. */
    public function authenticatedSecondsAgo(): ?int
    {
        $session = $this->readSession();

        return $session === null ? null : time() - (int) $session['started'];
    }

    public function destroy(): void
    {
        $sid = $this->cookieSid();
        if ($sid !== '') {
            $stored = get_transient(self::transientKey($sid));
            if (is_array($stored) && isset($stored['donor_id'])) {
                $this->untrack((int) $stored['donor_id'], $sid);
            }
            delete_transient(self::transientKey($sid));
        }
        $this->setCookie('', time() - 3600);
    }

    /** Every device. The cookie elsewhere survives but resolves to nothing. */
    public function destroyAllFor(int $donorId): int
    {
        $index = $this->index($donorId);
        foreach ($index as $hash) {
            delete_transient('dono_portal_' . $hash);
        }
        delete_transient(self::indexKey($donorId));

        if ($this->cookieSid() !== '') {
            $this->setCookie('', time() - 3600);
        }

        return count($index);
    }

    private function readSession(): ?array
    {
        $sid = $this->cookieSid();
        if ($sid === '') return null;

        $stored = get_transient(self::transientKey($sid));
        if (! is_array($stored) || ! isset($stored['donor_id'])) return null;

        $now     = time();
        $started = (int) ($stored['started'] ?? $now);
        if ($now - $started > self::MAX_SECONDS) {
            $this->untrack((int) $stored['donor_id'], $sid);
            delete_transient(self::transientKey($sid));
            return null;
        }

        if ($now - (int) ($stored['seen'] ?? $now) > self::REFRESH_AFTER) {
            $stored['seen'] = $now;
            set_transient(self::transientKey($sid), $stored, self::IDLE_SECONDS);
        }

        return [
            'donor_id' => (int) $stored['donor_id'],
            'csrf'     => isset($stored['csrf']) ? (string) $stored['csrf'] : '',
            'started'  => $started,
        ];
    }

    /** @return list<string> sid hashes */
    private function index(int $donorId): array
    {
        $stored = get_transient(self::indexKey($donorId));

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    private function track(int $donorId, string $sid): void
    {
        $index   = $this->index($donorId);
        $index[] = self::hash($sid);

        foreach (array_splice($index, 0, max(0, count($index) - self::MAX_PER_DONOR)) as $evicted) {
            delete_transient('dono_portal_' . $evicted);
        }

        set_transient(self::indexKey($donorId), $index, self::MAX_SECONDS);
    }

    private function untrack(int $donorId, string $sid): void
    {
        $index = array_values(array_diff($this->index($donorId), [self::hash($sid)]));
        if ($index === []) {
            delete_transient(self::indexKey($donorId));
            return;
        }
        set_transient(self::indexKey($donorId), $index, self::MAX_SECONDS);
    }

    private function cookieSid(): string
    {
        $sid = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';

        return $sid !== '' && ctype_xdigit($sid) ? $sid : '';
    }

    private function setCookie(string $value, ?int $expires = null): void
    {
        // Output already started (CLI/test, or a theme that echoes early).
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires ?? (time() + self::MAX_SECONDS),
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function hash(string $sid): string
    {
        return hash('sha256', $sid);
    }

    private static function transientKey(string $sid): string
    {
        return 'dono_portal_' . self::hash($sid);
    }

    private static function indexKey(int $donorId): string
    {
        return 'dono_portal_sids_' . $donorId;
    }
}
