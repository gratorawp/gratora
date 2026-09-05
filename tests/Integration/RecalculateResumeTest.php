<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use WP_REST_Request;

/**
 * Recalculate walked every donor, fund, campaign and form in one request. On
 * any site big enough to need the tool PHP hit max_execution_time part-way
 * through the donor pass: the admin got a 504, the later passes never ran, and
 * pressing the button again restarted from the first donor and stopped in the
 * same place forever.
 */
final class RecalculateResumeTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('fundkit_recalculate_cursor');

        // A budget too small to hold the whole walk, which is the shape of the
        // real failure: a table that outlasts the request.
        add_filter('fundkit.recalculate.budget_seconds', static fn (): float => 0.0);

        $this->seeded = [];
        for ($i = 0; $i < 6; $i++) {
            $d = Donor::make();
            $d->email_hash = hash('sha256', 'resume-' . $i . '-' . uniqid());
            $d->created_at = gmdate('Y-m-d H:i:s');
            $d->updated_at = $d->created_at;
            $d->save();
            $this->seeded[] = (int) $d->id;
        }
    }

    protected function tearDown(): void
    {
        delete_option('fundkit_recalculate_cursor');
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function post(string $scope = 'donors'): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/tools/recalculate');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['scope' => $scope]));

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status());

        return (array) $res->get_data();
    }

    public function test_a_run_that_cannot_finish_says_so_instead_of_claiming_success(): void
    {
        $first = $this->post();

        $this->assertFalse($first['done'], 'an unfinished rebuild reported as finished');
        $this->assertIsArray(get_option('fundkit_recalculate_cursor'), 'and left nothing to resume from');
    }

    public function test_pressing_on_finishes_the_walk_instead_of_restarting_it(): void
    {
        $rounds = 0;
        $counts = [];
        do {
            $res    = $this->post();
            $counts = $res['counts'];
            $rounds++;
        } while (! $res['done'] && $rounds < 200);

        $this->assertTrue($res['done'], 'the walk never finished');
        $this->assertGreaterThan(1, $rounds, 'fixture: the budget did split the walk');
        $this->assertGreaterThanOrEqual(
            count($this->seeded),
            (int) $counts['donors'],
            'donors past the cut-off kept their stale totals'
        );
    }

    public function test_a_finished_run_leaves_nothing_behind_to_resume(): void
    {
        $rounds = 0;
        do {
            $res = $this->post();
            $rounds++;
        } while (! $res['done'] && $rounds < 200);

        $this->assertFalse(get_option('fundkit_recalculate_cursor'), 'the next run would resume a finished one');
    }

    /** Asking for something else abandons the half-finished walk rather than resuming it. */
    public function test_changing_the_scope_starts_over(): void
    {
        $this->post('donors');
        $this->assertSame('donors', get_option('fundkit_recalculate_cursor')['scope']);

        $this->post('campaigns');

        $stored = get_option('fundkit_recalculate_cursor');
        $this->assertTrue($stored === false || $stored['scope'] === 'campaigns');
    }
}
