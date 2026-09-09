<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The donations screen fires stats() on every keystroke. A two-letter term
 * matched most of a large donor table, and every id went into one
 * donor_id IN (...) clause: a compiled statement running to megabytes, which
 * degrades sharply or is refused outright by a small max_allowed_packet.
 */
final class DonorSearchIsBoundedTest extends IntegrationTestCase
{
    private const SURNAME = 'Vandersloot';

    private function seedDonors(int $count): void
    {
        global $wpdb;

        $now    = gmdate('Y-m-d H:i:s');
        $tuples = [];
        for ($i = 0; $i < $count; $i++) {
            $tuples[] = $wpdb->prepare('(%s, %s, %s, %s, %s)', 'hash-' . $i . '-' . uniqid(), 'x', self::SURNAME, $now, $now);
        }

        $wpdb->query(
            'INSERT INTO ' . $wpdb->prefix . 'gratora_donors '
            . '(email_hash, email_encrypted, last_name, created_at, updated_at) VALUES '
            . implode(',', $tuples)
        );
    }

    public function test_a_search_matching_most_of_the_table_is_bounded(): void
    {
        $this->seedDonors(DonorService::SEARCH_MATCH_CAP + 1);

        $ids = Plugin::instance()->container->get(DonorService::class)->findIdsBySearch(self::SURNAME);

        $this->assertCount(DonorService::SEARCH_MATCH_CAP, $ids);
    }

    /** And the screen the cap protects still answers. */
    public function test_the_stats_route_still_answers_for_that_term(): void
    {
        $this->seedDonors(5);

        $req = new WP_REST_Request('GET', '/gratora/v1/admin/donations/stats');
        $req->set_query_params(['search' => self::SURNAME]);

        $this->assertSame(200, rest_do_request($req)->get_status());
    }
}
