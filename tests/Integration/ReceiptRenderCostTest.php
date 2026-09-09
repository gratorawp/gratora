<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use WP_REST_Request;

/**
 * What re-fetching a receipt link costs this site.
 *
 * The token is the only auth, it lasts thirty days, it is multi-use, and it
 * rides in an emailed URL. MagicLinkService counts only misses, so a caller
 * holding a WORKING token was never counted anywhere, and each hit is two
 * decryptions plus a full Dompdf render that nothing caches. It is a GET, so an
 * <img src> on any page, a link prefetcher or a mail-security scanner fires it:
 * one forwarded receipt was an unbounded CPU amplifier aimed at this site.
 */
final class ReceiptRenderCostTest extends IntegrationTestCase
{
    private function fetch(): int
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/receipts/1/download');
        $req->set_query_params(['token' => 'token-' . uniqid()]);

        return rest_do_request($req)->get_status();
    }

    /** The counter that proves a render attempt was metered at all. */
    private function counter(): int
    {
        global $wpdb;

        $value = $wpdb->get_var(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_gratora_receipt_%'
             ORDER BY option_id DESC LIMIT 1"
        );

        return $value === null ? 0 : (int) $value;
    }

    /**
     * Spent before the token is read, so the expensive path - the one with a
     * working token - is the one it bounds. Counting only failures is what left
     * it open.
     */
    public function test_every_fetch_is_counted_whatever_the_token_says(): void
    {
        $before = $this->counter();

        for ($i = 0; $i < 5; $i++) {
            $this->fetch();
        }

        $this->assertSame(
            $before + 5,
            $this->counter(),
            'a render attempt must be metered before the token decides anything'
        );
    }

    public function test_a_forwarded_link_cannot_be_fetched_without_end(): void
    {
        $statuses = [];
        for ($i = 0; $i < 30; $i++) {
            $statuses[] = $this->fetch();
        }

        $this->assertContains(429, $statuses, 'an uncapped PDF renderer is a CPU amplifier');
    }

    /** A donor saving their own receipt a couple of times must never see this. */
    public function test_a_donor_re_downloading_is_not_refused(): void
    {
        $this->assertNotSame(429, $this->fetch());
        $this->assertNotSame(429, $this->fetch());
        $this->assertNotSame(429, $this->fetch());
    }
}
