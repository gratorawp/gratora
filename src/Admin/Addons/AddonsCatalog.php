<?php

declare(strict_types=1);

namespace Gratora\Admin\Addons;

use DateTimeImmutable;
use DateTimeZone;
use Gratora\Foundation\Time\Clock;

/**
 * The add-ons, plans and offer the Add-ons screen shows: from gratora.net when
 * it answers, from the list below when it does not.
 *
 * @since 1.1.3
 */
final class AddonsCatalog
{
    public const ENDPOINT = 'https://gratora.net/wp-json/gratora-license/v1/addons';

    private const HOST      = 'gratora.net';
    private const VERSION   = 1;
    private const CACHE     = 'gratora_addons_catalog';

    /** Raised when what is kept changes, so what another version kept is not read. */
    private const SHAPE = 1;

    private const MAX_BYTES = 65536;
    private const MAX_CARDS = 30;
    private const MAX_PLANS = 6;

    /** The same on every site, so it names the plugin as the source of a visit and not the site. */
    private const MARK = '?utm_source=plugin&utm_medium=add-ons';

    /** @since 1.1.3 */
    public function __construct(private Clock $clock)
    {
    }

    /**
     * @return array{
     *   source:'remote'|'builtin',
     *   addons:list<array{slug:string,file:string,name:string,description:string,icon:string,url:string,free:bool}>,
     *   plans:list<array{slug:string,name:string,summary:string,sites:int,addons:list<string>,url:string}>,
     *   offer:array{text:string,url:string}|null,
     * }
     *
     * @since 1.1.3
     */
    public function get(): array
    {
        $feed = $this->remote();

        if ($feed === null) {
            return ['source' => 'builtin', 'addons' => self::builtIn(), 'plans' => [], 'offer' => null];
        }

        return [
            'source' => 'remote',
            'addons' => $feed['addons'],
            'plans'  => $feed['plans'],
            'offer'  => $this->running($feed['offer']),
        ];
    }

    /** @return array{addons:list<array<string,mixed>>,plans:list<array<string,mixed>>,offer:array{text:string,url:string,ends:int|null}|null}|null */
    private function remote(): ?array
    {
        /**
         * Whether the Add-ons screen may ask gratora.net for its lists.
         *
         * @since 1.1.3
         *
         * @param bool $allowed
         */
        if (! apply_filters('gratora.addons.remote', true)) {
            return null;
        }

        $kept = get_transient(self::CACHE);
        if (is_array($kept) && ($kept['shape'] ?? null) === self::SHAPE && array_key_exists('feed', $kept)) {
            return $kept['feed'];
        }

        $feed = $this->fetch();
        set_transient(self::CACHE, ['shape' => self::SHAPE, 'feed' => $feed], $feed === null ? HOUR_IN_SECONDS : DAY_IN_SECONDS);

        return $feed;
    }

    /** @return array{addons:list<array<string,mixed>>,plans:list<array<string,mixed>>,offer:array{text:string,url:string,ends:int|null}|null}|null */
    private function fetch(): ?array
    {
        // WordPress would name the site's address in the user agent.
        $response = wp_safe_remote_get(self::ENDPOINT, [
            'timeout'             => 3,
            'redirection'         => 0,
            'user-agent'          => 'Gratora/' . GRATORA_VERSION,
            'limit_response_size' => self::MAX_BYTES + 1,
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $data = json_decode($body, true);
        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            return null;
        }

        $addons = self::cards($data['addons'] ?? null);
        if ($addons === []) {
            return null;
        }

        return [
            'addons' => $addons,
            'plans'  => self::plans($data['plans'] ?? null, array_column($addons, 'slug')),
            'offer'  => self::offer($data['offer'] ?? null),
        ];
    }

    /** @return list<array{slug:string,file:string,name:string,description:string,icon:string,url:string,free:bool}> */
    private static function cards(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $cards = [];
        foreach (array_slice($entries, 0, self::MAX_CARDS) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $slug        = self::slug($entry['slug'] ?? null);
            $file        = $entry['file'] ?? null;
            $name        = self::text($entry['name'] ?? null, 60);
            $description = self::text($entry['description'] ?? null, 200);
            $url         = self::url($entry['url'] ?? null);
            $icon        = $entry['icon'] ?? null;

            if ($slug === null || isset($cards[$slug]) || $name === null || $description === null || $url === null
                || ! is_string($file) || preg_match('/^gratora-[a-z0-9-]+\.php\z/', $file) !== 1
            ) {
                continue;
            }

            $cards[$slug] = [
                'slug'        => $slug,
                'file'        => $file,
                'name'        => $name,
                'description' => $description,
                'icon'        => is_string($icon) && preg_match('/^[a-z0-9-]{1,40}\z/', $icon) === 1 ? $icon : '',
                'url'         => $url,
                'free'        => ! empty($entry['free']),
            ];
        }

        return array_values($cards);
    }

    /**
     * @param  list<string> $onScreen slugs of the cards that are shown
     * @return list<array{slug:string,name:string,summary:string,sites:int,addons:list<string>,url:string}>
     */
    private static function plans(mixed $entries, array $onScreen): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $plans = [];
        foreach (array_slice($entries, 0, self::MAX_PLANS) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $slug  = self::slug($entry['slug'] ?? null);
            $name  = self::text($entry['name'] ?? null, 40);
            $url   = self::url($entry['url'] ?? null);
            $sites = $entry['sites'] ?? null;
            $named = $entry['addons'] ?? null;

            if ($slug === null || isset($plans[$slug]) || $name === null || $url === null
                || ! is_int($sites) || $sites < 1 || $sites > 1000 || ! is_array($named)
            ) {
                continue;
            }

            $addons = array_values(array_unique(array_intersect(array_filter($named, 'is_string'), $onScreen)));
            if ($addons === []) {
                continue;
            }

            $plans[$slug] = [
                'slug'    => $slug,
                'name'    => $name,
                'summary' => self::text($entry['summary'] ?? null, 100) ?? '',
                'sites'   => $sites,
                'addons'  => $addons,
                'url'     => $url,
            ];
        }

        return array_values($plans);
    }

    /** @return array{text:string,url:string,ends:int|null}|null */
    private static function offer(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $text = self::text($entry['text'] ?? null, 140);
        $url  = self::url($entry['url'] ?? null);
        if ($text === null || $url === null) {
            return null;
        }

        if (! array_key_exists('ends', $entry)) {
            return ['text' => $text, 'url' => $url, 'ends' => null];
        }

        // An end that cannot be read must not become an offer without one.
        $ends = self::moment($entry['ends']);

        return $ends === null ? null : ['text' => $text, 'url' => $url, 'ends' => $ends];
    }

    /**
     * @param  array{text:string,url:string,ends:int|null}|null $offer
     * @return array{text:string,url:string}|null
     */
    private function running(?array $offer): ?array
    {
        if ($offer === null || ($offer['ends'] !== null && $this->clock->now()->getTimestamp() > $offer['ends'])) {
            return null;
        }

        return ['text' => $offer['text'], 'url' => $offer['url']];
    }

    private static function slug(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9-]{1,60}\z/', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($value)));

        return $text === '' || mb_strlen($text) > $max ? null : $text;
    }

    private static function url(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 200 || esc_url_raw($value, ['https']) !== $value) {
            return null;
        }

        $parts = wp_parse_url($value);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === self::HOST
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            ? $value
            : null;
    }

    /** A UTC moment written as 2026-10-31T23:59:59Z, as a timestamp. */
    private static function moment(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $at !== false && $at->format('Y-m-d\TH:i:s\Z') === $value ? $at->getTimestamp() : null;
    }

    /** @return list<array{slug:string,file:string,name:string,description:string,icon:string,url:string,free:bool}> */
    private static function builtIn(): array
    {
        $paid = [
            ['peer-to-peer-fundraising', 'gratora-p2p.php', 'Peer-to-Peer', 'Supporters raise money for you on their own pages, alone or in teams.', 'users-round'],
            ['events', 'gratora-events.php', 'Event Tickets', 'Sell tickets to fundraising events, then check guests in by phone.', 'ticket'],
            ['ai-assistant', 'gratora-ai-assistant.php', 'AI Assistant', 'Ask about your fundraising and make changes in plain language.', 'sparkles'],
            ['payment-gateways', 'gratora-payment-gateways.php', 'Payment Gateways', 'Take donations through Authorize.Net, Square, GoCardless, Moneris or Razorpay.', 'credit-card'],
            ['conversion-tracking', 'gratora-conversion-tracking.php', 'Conversion Tracking', 'Report completed donations to GA4, Google Ads and Meta, with amount and currency.', 'chart-line'],
            ['connect', 'gratora-connect.php', 'Connect', 'Send donation, donor and recurring events to signed webhooks, Slack and Mailchimp.', 'webhook'],
            ['tributes', 'gratora-tributes.php', 'Tributes', 'Donors dedicate a donation in honor or in memory of someone.', 'rose'],
            ['gift-aid', 'gratora-gift-aid.php', 'Gift Aid', 'Collect UK Gift Aid declarations on your forms and prepare your claim for HMRC.', 'landmark'],
            ['donation-recovery', 'gratora-donation-recovery.php', 'Donation Recovery', 'One reminder email to someone who started a donation and did not finish it.', 'mail'],
        ];

        $cards = [];
        foreach ($paid as [$slug, $file, $name, $description, $icon]) {
            $cards[] = [
                'slug'        => $slug,
                'file'        => $file,
                'name'        => $name,
                'description' => $description,
                'icon'        => $icon,
                'url'         => 'https://' . self::HOST . '/add-ons/' . $slug . '/' . self::MARK,
                'free'        => false,
            ];
        }

        $cards[] = [
            'slug'        => 'give-importer',
            'file'        => 'gratora-give-importer.php',
            'name'        => 'GiveWP Importer',
            'description' => 'Copy donors, donations, campaigns and recurring subscriptions from GiveWP into Gratora.',
            'icon'        => 'import',
            'url'         => 'https://' . self::HOST . '/add-ons/' . self::MARK . '#importer',
            'free'        => true,
        ];

        return $cards;
    }
}
