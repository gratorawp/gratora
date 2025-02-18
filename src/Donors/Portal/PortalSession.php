<?php

declare(strict_types=1);

namespace Dono\Donors\Portal;

use Dono\Donors\DonorRepository;
use Dono\Donors\MagicLinkService;

/**
 * Manages authenticated donor portal sessions via cookie and transient.
 *
 * @version 1.0.0
 */
final class PortalSession
{
    private const COOKIE      = 'dono_donor_session';
    private const TTL_SECONDS = 2_592_000;

    public function __construct(
        private MagicLinkService $magicLinks,
        private DonorRepository $donors,
    ) {
    }

    /**
     * Exchanges a raw magic-link token for an authenticated portal session.
     *
     * @return array{donor_id:int,csrf:string}|null
     */
    public function startFromToken(string $rawToken): ?array
    {
        // Single-use: once redeemed the session cookie carries the donor.
        $token = $this->magicLinks->consumeAndValidate($rawToken, 'donor_portal');
        if (! $token) return null;
        return $this->open((int) $token->donor_id);
    }

    /**
     * Opens a portal session. Returns donor id and a session-bound CSRF token.
     *
     * @return array{donor_id:int,csrf:string}
     */
    public function open(int $donorId): array
    {
        $sid  = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        set_transient(self::transientKey($sid), [
            'donor_id' => $donorId,
            'csrf'     => $csrf,
        ], self::TTL_SECONDS);
        $this->setCookie($sid);
        return ['donor_id' => $donorId, 'csrf' => $csrf];
    }

    public function currentDonorId(): ?int
    {
        $session = $this->readSession();
        return $session['donor_id'] ?? null;
    }

    public function csrfToken(): ?string
    {
        $session = $this->readSession();
        return $session['csrf'] ?? null;
    }

    public function destroy(): void
    {
        $sid = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        if ($sid !== '' && ctype_xdigit($sid)) {
            delete_transient(self::transientKey($sid));
        }
        $this->setCookie('', time() - 3600);
    }

    /** @return array{donor_id:int,csrf:string}|null */
    private function readSession(): ?array
    {
        $sid = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        if ($sid === '' || ! ctype_xdigit($sid)) return null;
        $stored = get_transient(self::transientKey($sid));
        if (is_array($stored) && isset($stored['donor_id'])) {
            return [
                'donor_id' => (int) $stored['donor_id'],
                'csrf'     => isset($stored['csrf']) ? (string) $stored['csrf'] : '',
            ];
        }
        return null;
    }

    private function setCookie(string $value, ?int $expires = null): void
    {
        $expires = $expires ?? (time() + self::TTL_SECONDS);
        // No-op if output already started (CLI/test, or a theme that echoes
        // early): setcookie() would only warn and fail to set the header.
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function transientKey(string $sid): string
    {
        return 'dono_portal_' . hash('sha256', $sid);
    }
}
