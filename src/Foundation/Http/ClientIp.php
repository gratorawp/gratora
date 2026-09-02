<?php

declare(strict_types=1);

namespace FundKit\Foundation\Http;

/**
 * Who is calling, for the purpose of counting them.
 *
 * REMOTE_ADDR is the only address the network guarantees, and it is what this
 * answers unless the site says otherwise. Behind a CDN or a reverse proxy it is
 * the proxy for every visitor, so a per-address cap becomes one bucket the
 * whole site shares: an attacker takes the donation form offline for everyone,
 * and on a busy day donors lock each other out with no attacker at all.
 *
 * The fix cannot be to read X-Forwarded-For, because anyone can send one. That
 * turns a cap that is too small into no cap at all, which is worse. A forwarded
 * header is read only when the hop that wrote it is an address the site has
 * declared as its own infrastructure.
 *
 * @since 1.0.0
 */
final class ClientIp
{
    /**
     * The address to attribute this request to.
     *
     * @since 1.0.0
     */
    public static function resolve(): string
    {
        $remote = self::remote();

        // For a host that already normalises REMOTE_ADDR, or puts the client
        // somewhere of its own choosing. Answered first because a site that
        // knows its own edge knows better than any of the below.
        $override = apply_filters('fundkit.spam.client_ip', null, $remote);
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
        $privacy = get_option('fundkit_privacy', []);
        $stored  = is_array($privacy) ? ($privacy['trusted_proxies'] ?? []) : [];
        $stored  = is_array($stored) ? $stored : [];

        $ranges = apply_filters('fundkit.spam.trusted_proxies', $stored);
        if (! is_array($ranges)) {
            return [];
        }

        $clean = [];
        foreach ($ranges as $range) {
            $range = trim((string) $range);
            if ($range !== '' && self::isValidRange($range)) {
                $clean[] = $range;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Whether this request arrived through a proxy the site has not declared.
     *
     * A private or loopback REMOTE_ADDR on a site being reached from outside
     * can only mean something in front is terminating the connection, so the
     * per-address cap is already one bucket for everyone. This is a fact about
     * the request rather than a guess, which is what makes it worth telling an
     * admin about.
     *
     * @since 1.0.0
     */
    public static function looksProxied(): bool
    {
        if (self::trustedProxies() !== []) {
            return false;
        }

        $remote = self::remote();
        if ($remote === '') {
            return false;
        }

        // A forwarded header from an undeclared source is not believed, but it
        // is still evidence that something in front wrote one.
        $forwarded = self::header('HTTP_X_FORWARDED_FOR') !== ''
            || self::header('HTTP_CF_CONNECTING_IP') !== '';

        return $forwarded && self::isPrivate($remote);
    }

    /**
     * The first hop the chain does not attribute to our own infrastructure.
     *
     * X-Forwarded-For is appended left to right, so the leftmost entry is
     * whatever the original caller sent and the rightmost are the ones our
     * own hops wrote. Reading it from the left is the classic mistake, and it
     * is exactly as exploitable as believing the header outright: a caller
     * pre-populates it and the proxy appends beneath.
     *
     * Cloudflare sets this header like any other proxy, so it needs no case of
     * its own. CF-Connecting-IP is deliberately not read: it would be believed
     * on any trusted proxy, including one that does not set or strip it, and
     * an nginx that forwards headers untouched would pass an attacker's
     * straight through.
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
        return trim((string) wp_unslash($_SERVER[$key] ?? ''));
    }

    /**
     * One hop, as an address. Entries arrive carrying ports and brackets.
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
