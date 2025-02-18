<?php

declare(strict_types=1);

namespace Dono\Donations;

/**
 * Maps `donations.source_attribution` (utm_source/utm_medium) into a canonical
 * channel bucket. Used by every aggregator that wants channel-grouped numbers.
 *
 * @version 1.0.0
 */
final class ChannelClassifier
{
    private const MEDIUM_MAP = [
        'email'          => 'email',
        'newsletter'     => 'email',
        'social'         => 'social',
        'organic-social' => 'social',
        'paid-social'    => 'paid-social',
        'paidsocial'     => 'paid-social',
        'social-paid'    => 'paid-social',
        'organic'        => 'organic',
        'search'         => 'organic',
        'cpc'            => 'cpc',
        'ppc'            => 'cpc',
        'paid-search'    => 'cpc',
        'paidsearch'     => 'cpc',
        'referral'       => 'referral',
        'qr'             => 'qr',
        'qr-code'        => 'qr',
        'event'          => 'qr',
        'peer'           => 'peer',
        'p2p'            => 'peer',
        'fundraiser'     => 'peer',
    ];

    private const SOCIAL_SOURCES = [
        'facebook', 'fb', 'instagram', 'ig', 'twitter', 'x',
        'linkedin', 'tiktok', 'youtube', 'pinterest',
    ];

    private const SEARCH_SOURCES = [
        'google', 'bing', 'duckduckgo', 'yahoo', 'ecosia',
    ];

    private const EMAIL_KEYWORDS = [
        'newsletter', 'mailchimp', 'sendinblue', 'mailerlite', 'mailgun',
    ];

    /** @param array<string,mixed> $attr */
    public static function classify(array $attr): string
    {
        $source = strtolower(trim((string) ($attr['utm_source'] ?? '')));
        $medium = strtolower(trim((string) ($attr['utm_medium'] ?? '')));

        if (isset(self::MEDIUM_MAP[$medium])) return self::MEDIUM_MAP[$medium];

        if (in_array($source, self::SOCIAL_SOURCES, true)) return 'social';
        if (in_array($source, self::SEARCH_SOURCES, true)) return 'organic';

        foreach (self::EMAIL_KEYWORDS as $kw) {
            if ($source !== '' && str_contains($source, $kw)) return 'email';
        }

        if ($medium === '' && $source === '') return 'direct';
        return 'referral';
    }
}
