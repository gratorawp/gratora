<?php

declare(strict_types=1);

namespace Gratora\Foundation\Http;

/**
 * Trust REMOTE_ADDR unless it belongs to configured proxy infrastructure. Only then inspect
 * forwarded headers to separate visitor quotas safely.
 *
 * @since 1.0.0
 */
final class ClientIp
{
    /** RFC1918, loopback and unique-local: addresses only reachable from inside. */
    private const PRIVATE_RANGES = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8',
        '169.254.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    /**
     * Cloudflare's published edge ranges.
     *
     * Pinned rather than fetched: a site's spam limits must not depend on an
     * outbound request succeeding, and these change rarely and only by
     * addition. A range added after this ships makes that edge unrecognised,
     * which costs the shared-bucket behaviour the site had anyway, and never
     * trusts anything it should not.
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @var array<string, list<string>> */
    private const KEYWORDS = [
        'cloudflare'     => self::CLOUDFLARE_RANGES,
        'private_ranges' => self::PRIVATE_RANGES,
    ];

    /** @since 1.0.0 */
    public static function resolve(): string
    {
        $remote = self::remote();

        // For a host that already normalises REMOTE_ADDR, or puts the client
        // somewhere of its own choosing. Answered first because a site that
        // knows its own edge knows better than any of the below.
        $override = apply_filters('gratora.spam.client_ip', null, $remote);
        if (is_string($override) && filter_var($override, FILTER_VALIDATE_IP)) {
            return $override;
        }

        $trusted = self::trustedProxies();
        if ($trusted === [] || $remote === '' || ! self::inRanges($remote, $trusted)) {
            return $remote;
        }

        // Falls back to the proxy's own address, never to a constant: a
        // constant would merge every request with a missing or malformed
        // header into one bucket, which is the outage this exists to prevent.
        return self::forwardedClient($trusted) ?? $remote;
    }

    /**
     * The address the network reports, validated. Never a header.
     *
     * @since 1.0.0
     */
    public static function remote(): string
    {
        $ip = filter_var(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);

        return is_string($ip) ? $ip : '';
    }

    /**
     * CIDRs whose forwarded headers this site will read.
     *
     * Empty by default, so a site that declares nothing behaves exactly as it
     * did: REMOTE_ADDR, and no header is believed.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public static function trustedProxies(): array
    {
        $privacy = get_option('gratora_privacy', []);
        $stored  = is_array($privacy) ? ($privacy['trusted_proxies'] ?? []) : [];
        $stored  = is_array($stored) ? $stored : [];

        $ranges = apply_filters('gratora.spam.trusted_proxies', $stored);
        if (! is_array($ranges)) {
            return [];
        }

        $clean = [];
        foreach ($ranges as $range) {
            $range = strtolower(trim((string) $range));
            if ($range === '') {
                continue;
            }

            // Words, because the people who need this setting are not the
            // people who know what a CIDR is. "cloudflare" is a fact about
            // the site an admin can confirm from their own dashboard; the
            // ranges behind it are ours to keep current.
            if (isset(self::KEYWORDS[$range])) {
                foreach (self::KEYWORDS[$range] as $expanded) {
                    $clean[] = $expanded;
                }
                continue;
            }

            if (self::isValidRange($range)) {
                $clean[] = $range;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Whether the site can act on this entry as written. The panel counts what
     * is stored; this is what the resolver keeps, and an entry only one of them
     * recognises leaves every visitor sharing one spam bucket with nothing said.
     *
     * @since 1.0.0
     */
    public static function understands(string $entry): bool
    {
        $entry = strtolower(trim($entry));

        return $entry !== '' && (isset(self::KEYWORDS[$entry]) || self::isValidRange($entry));
    }

    /**
     * What is sitting in front of this site, if the site has not said.
     *
     * Returns the keyword that would fix it, so the answer an admin is given
     * is the answer they can act on rather than a description of a problem.
     *
     * Cloudflare announces itself: CF-Ray is on every request it proxies, and
     * its edge is public, so the private-address test alone would miss the
     * commonest case of all. A private REMOTE_ADDR is the other: an address
     * only reachable from inside can only be this site's own infrastructure.
     *
     * @return 'cloudflare'|'private_ranges'|null
     *
     * @since 1.0.0
     */
    public static function undeclaredProxy(): ?string
    {
        if (self::trustedProxies() !== []) {
            return null;
        }

        if (self::header('HTTP_CF_RAY') !== '') {
            return 'cloudflare';
        }

        $remote = self::remote();
        if ($remote === '') {
            return null;
        }

        // A forwarded header from an undeclared source is not believed, but it
        // is still evidence that something in front wrote one.
        $forwarded = self::header('HTTP_X_FORWARDED_FOR') !== ''
            || self::header('HTTP_CF_CONNECTING_IP') !== '';

        return $forwarded && self::isPrivate($remote) ? 'private_ranges' : null;
    }

    /** @since 1.0.0 */
    public static function looksProxied(): bool
    {
        return self::undeclaredProxy() !== null;
    }

    /**
     * Walk X-Forwarded-For from the trusted right end to the first untrusted hop. Ignore
     * CF-Connecting-IP because other trusted proxies may forward it unfiltered.
     *
     * @param list<string> $trusted
     *
     * @since 1.0.0
     */
    private static function forwardedClient(array $trusted): ?string
    {
        $header = self::header('HTTP_X_FORWARDED_FOR');
        if ($header === '') {
            return null;
        }

        $hops = explode(',', $header);
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = self::normaliseHop($hops[$i]);
            if ($hop === '') {
                continue;
            }
            if (self::inRanges($hop, $trusted)) {
                continue;
            }

            return $hop;
        }

        return null;
    }

    /** @since 1.0.0 */
    private static function header(string $key): string
    {
        return sanitize_text_field(wp_unslash($_SERVER[$key] ?? ''));
    }

    /**
     * Strip ports and brackets from proxy hops.
     *
     * @since 1.0.0
     */
    private static function normaliseHop(string $hop): string
    {
        $hop = trim($hop);

        // "[2001:db8::1]:443", the bracketed form a port requires.
        if (str_starts_with($hop, '[')) {
            $close = strpos($hop, ']');
            if ($close !== false) {
                $hop = substr($hop, 1, $close - 1);
            }
        } elseif (substr_count($hop, ':') === 1) {
            // "203.0.113.7:41234". A bare IPv6 has more than one colon, so it
            // is left alone.
            $hop = substr($hop, 0, (int) strpos($hop, ':'));
        }

        $ip = filter_var($hop, FILTER_VALIDATE_IP);

        return is_string($ip) ? $ip : '';
    }

    /**
     * @param list<string> $ranges
     *
     * @since 1.0.0
     */
    public static function inRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefix match over the packed address, so one implementation serves both
     * families and a v4 range can never match a v6 address.
     *
     * @since 1.0.0
     */
    private static function inRange(string $ip, string $range): bool
    {
        $parts  = explode('/', trim($range), 2);
        $subnet = $parts[0];

        $ipBin  = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits    = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : $maxBits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $whole = intdiv($bits, 8);
        if ($whole > 0 && strncmp($ipBin, $subBin, $whole) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (~((1 << (8 - $remainder)) - 1)) & 0xFF;

        return (ord($ipBin[$whole]) & $mask) === (ord($subBin[$whole]) & $mask);
    }

    /** @since 1.0.0 */
    private static function isValidRange(string $range): bool
    {
        $parts = explode('/', $range, 2);
        if (@inet_pton($parts[0]) === false) {
            return false;
        }
        if (! isset($parts[1]) || $parts[1] === '') {
            return true;
        }

        return ctype_digit($parts[1]) && (int) $parts[1] <= (strlen((string) @inet_pton($parts[0])) * 8);
    }

    /** @since 1.0.0 */
    public static function isPrivate(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
