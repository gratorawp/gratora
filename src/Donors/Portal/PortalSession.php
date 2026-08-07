<?php

declare(strict_types=1);

namespace Dono\Donors\Portal;

use Dono\Donors\DonorRepository;
use Dono\Donors\MagicLinkService;
use Dono\Donors\SignupRedemption;

final class PortalSession
{
    private const COOKIE      = 'dono_donor_session';
    private const TTL_SECONDS = 2_592_000;

    public function __construct(
        private MagicLinkService $magicLinks,
        private DonorRepository $donors,
        private SignupRedemption $signups,
    ) {
    }

    /**
     * Two kinds of link arrive here. A sign-in link names a donor who already
     * exists. A signup link names none: it points at an address somebody
     * claimed, and redeeming it is the moment that claim becomes a donor.
     * Whoever opens the link controls the mailbox, which is the only evidence
     * the address was ever theirs.
     */
    public function startFromToken(string $rawToken): ?array
    {
        // Single-use: once redeemed the session cookie carries the donor.
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
        // Output already started (CLI/test, or a theme that echoes early):
        // setcookie() would only warn and fail to set the header.
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
