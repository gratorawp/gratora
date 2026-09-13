<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use WP_REST_Request;

/**
 * Rows an add-on left behind while it was switched off.
 *
 * Every add-on clears its own rows by listening to a core action, so nothing
 * is cleared while the add-on is not booted, and what survives is then
 * unreachable: on this owner's site two campaigns deleted with gratora-p2p
 * inactive stranded two fundraisers, a team and six published pages, and the
 * admin reaches a fundraiser list through a campaign, so no screen could ever
 * show them.
 *
 * Core cannot know what any of that is. It asks, shows what it is told, and
 * clears only when somebody types the word.
 */
final class OrphanReconciliationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** @return array<string,mixed> */
    private function info(): array
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/tools/info'));
        $this->assertSame(200, $res->get_status());

        return (array) $res->get_data();
    }

    private function clear(string $confirmation): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/admin/tools/clear-orphans');
        $req->set_param('confirmation', $confirmation);

        return rest_do_request($req);
    }

    public function test_an_add_on_can_report_what_it_has_stranded(): void
    {
        $report = static function (array $found): array {
            $found[] = ['key' => 'p2p_fundraisers', 'label' => 'Fundraiser pages with no campaign', 'count' => 2];

            return $found;
        };
        add_filter('gratora.orphans.report', $report);

        try {
            $info = $this->info();
        } finally {
            remove_filter('gratora.orphans.report', $report);
        }

        $this->assertSame(
            [['key' => 'p2p_fundraisers', 'label' => 'Fundraiser pages with no campaign', 'count' => 2]],
            $info['orphans']
        );
    }

    /** A healthy site says nothing, which is what makes the card worth reading. */
    public function test_nothing_stranded_reports_nothing(): void
    {
        $this->assertSame([], $this->info()['orphans']);
    }

    /** A count of zero is not news and must not raise the card. */
    public function test_a_zero_count_is_dropped(): void
    {
        $report = static function (array $found): array {
            $found[] = ['key' => 'quiet', 'label' => 'Nothing at all', 'count' => 0];

            return $found;
        };
        add_filter('gratora.orphans.report', $report);

        try {
            $this->assertSame([], $this->info()['orphans']);
        } finally {
            remove_filter('gratora.orphans.report', $report);
        }
    }

    public function test_clearing_needs_the_word_typed(): void
    {
        $this->assertSame(400, $this->clear('yes')->get_status());
    }

    public function test_clearing_tells_each_add_on_and_reports_what_went(): void
    {
        $cleared = 0;
        $clear   = static function (array $removed) use (&$cleared): array {
            $cleared++;
            $removed[] = ['key' => 'p2p_fundraisers', 'label' => 'Fundraiser pages with no campaign', 'count' => 2];

            return $removed;
        };
        add_filter('gratora.orphans.clear', $clear);

        try {
            $res = $this->clear('DELETE');
        } finally {
            remove_filter('gratora.orphans.clear', $clear);
        }

        $this->assertSame(200, $res->get_status());
        $this->assertSame(1, $cleared, 'asked once');
        $this->assertSame(2, (int) $res->get_data()['removed'][0]['count']);
    }

    /** The act is recorded: it removes rows nobody could see beforehand. */
    public function test_clearing_is_written_down(): void
    {
        $clear = static function (array $removed): array {
            $removed[] = ['key' => 'ga_claims', 'label' => 'Gift Aid claims with no donation', 'count' => 3];

            return $removed;
        };
        add_filter('gratora.orphans.clear', $clear);

        try {
            $this->clear('DELETE');
        } finally {
            remove_filter('gratora.orphans.clear', $clear);
        }

        $rows = \Gratora\Analytics\Event::query()->where('type', 'donor.orphans_cleared')->getAll();
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) ((array) $rows[0]->payload)['removed']['ga_claims']);
    }

    /** And it reads as something, not as a row saying nothing was recorded. */
    public function test_the_record_says_who_cleared_it(): void
    {
        $clear = static function (array $removed): array {
            $removed[] = ['key' => 'ga_claims', 'label' => 'Gift Aid claims with no donation', 'count' => 3];

            return $removed;
        };
        add_filter('gratora.orphans.clear', $clear);

        try {
            $this->clear('DELETE');
        } finally {
            remove_filter('gratora.orphans.clear', $clear);
        }

        $req  = new WP_REST_Request('GET', '/gratora/v1/admin/tools/log');
        $rows = (array) ((array) rest_do_request($req)->get_data())['items'];

        $mine = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['source'] === 'donor.orphans_cleared'
        ));

        $this->assertCount(1, $mine);
        $this->assertNotSame(__('No detail recorded.', 'gratora-donation-platform'), $mine[0]['message']);
        $this->assertSame(3, (int) $mine[0]['context']['removed']['ga_claims']);
    }

    public function test_a_reader_without_manage_options_cannot_clear(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertContains($this->clear('DELETE')->get_status(), [401, 403]);
    }
}
