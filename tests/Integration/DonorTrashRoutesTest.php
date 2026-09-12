<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The donor bin's routes, and who may reach them.
 *
 * Run against a scoped role rather than an administrator: userCan accepts
 * manage_options first, so an administrator passes every check here and the
 * capability assertions would prove nothing.
 */
final class DonorTrashRoutesTest extends IntegrationTestCase
{
    private const VIEW   = 'gratora_view_donors';
    private const EDIT   = 'gratora_edit_donors';
    private const REDACT = 'gratora_redact_donors';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option('gratora_roles');
        Capabilities::applyMapping([]);
    }

    protected function tearDown(): void
    {
        Capabilities::applyMapping([]);
        parent::tearDown();
    }

    /** @param list<string> $caps */
    private function asRoleWith(array $caps): void
    {
        Capabilities::applyMapping(['editor' => $caps]);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    private function signup(string $label): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)->findOrCreate(
            $label . '-' . uniqid() . '@example.test',
            ['first_name' => 'Route', 'last_name' => 'Probe']
        );
    }

    private function paidDonation(int $donorId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = 'ROUTE-' . bin2hex(random_bytes(4));
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->base_currency     = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /** @param list<int> $ids */
    private function post(string $route, array $ids): \WP_REST_Response|\WP_Error
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/admin/donors/' . $route);
        $request->set_param('ids', $ids);

        return rest_do_request($request);
    }

    private function statusOf(mixed $response): int
    {
        return $response instanceof \WP_Error
            ? (int) ($response->get_error_data()['status'] ?? 0)
            : (int) $response->get_status();
    }

    public function test_reading_donors_does_not_carry_the_right_to_bin_one(): void
    {
        $this->asRoleWith([self::VIEW]);
        $donor = $this->signup('view-only');

        $this->assertSame(403, $this->statusOf($this->post('trash', [(int) $donor->id])));
        $this->assertNull(Donor::query()->find('id', (int) $donor->id)->trashed_at);
    }

    public function test_the_edit_capability_bins_and_restores(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);
        $donor = $this->signup('editor');
        $id    = (int) $donor->id;

        $this->assertSame(200, $this->statusOf($this->post('trash', [$id])));
        $this->assertNotNull(Donor::query()->find('id', $id)->trashed_at);

        $this->assertSame(200, $this->statusOf($this->post('restore', [$id])));
        $this->assertNull(Donor::query()->find('id', $id)->trashed_at);
    }

    /**
     * Binning is reversible and touches no money, so it must not require the
     * capability that erases people.
     */
    public function test_binning_does_not_require_the_redact_capability(): void
    {
        $this->asRoleWith([self::VIEW, self::REDACT]);
        $donor = $this->signup('redact-only');

        $this->assertSame(403, $this->statusOf($this->post('trash', [(int) $donor->id])));
    }

    public function test_a_selection_larger_than_a_page_is_refused(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);

        $this->assertSame(400, $this->statusOf($this->post('trash', range(1, 51))));
    }

    /**
     * A mixed selection has to say which rows it could not move and why, or
     * the screen shows a count with nothing attached to it.
     */
    public function test_a_mixed_batch_answers_per_row(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);

        $binnable = $this->signup('mixed-ok');
        $hasMoney = $this->signup('mixed-kept');
        $this->paidDonation((int) $hasMoney->id);

        $body = $this->post('trash', [(int) $binnable->id, (int) $hasMoney->id])->get_data();

        $this->assertSame([['id' => (int) $binnable->id]], $body['done']);
        $this->assertCount(1, $body['refused']);
        $this->assertSame((int) $hasMoney->id, $body['refused'][0]['id']);
        $this->assertNotSame('', (string) $body['refused'][0]['reason'], 'and it says why');
    }

    public function test_asking_twice_reports_the_second_as_already_done(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);
        $id = (int) $this->signup('twice')->id;

        $this->post('trash', [$id]);
        $body = $this->post('trash', [$id])->get_data();

        $this->assertSame([], $body['done']);
        $this->assertSame([['id' => $id]], $body['already']);
    }

    public function test_the_list_carries_the_bins_size_and_can_show_it(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);
        $binned = $this->signup('header');
        $this->post('trash', [(int) $binned->id]);

        $live = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/donors'));
        $this->assertSame('1', (string) $live->get_headers()['X-Gratora-Trashed']);
        $this->assertNotContains(
            (int) $binned->id,
            array_map(static fn (array $r): int => (int) $r['id'], $live->get_data())
        );

        $request = new WP_REST_Request('GET', '/gratora/v1/admin/donors');
        $request->set_param('trashed', 'only');
        $bin = rest_do_request($request);

        $this->assertSame(
            [(int) $binned->id],
            array_map(static fn (array $r): int => (int) $r['id'], $bin->get_data())
        );
        $this->assertTrue($bin->get_data()[0]['trashed']);
        $this->assertNotNull($bin->get_data()[0]['trashed_at']);
    }

    /**
     * The headcount above the rows is a count of people, not money, so it has
     * to follow whichever view is open.
     */
    public function test_the_headcount_follows_the_view(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);
        // Two kept and one binned, so the two counts cannot agree by accident.
        $this->signup('stats-kept-a');
        $this->signup('stats-kept-b');
        $binned = $this->signup('stats-binned');
        $this->post('trash', [(int) $binned->id]);

        $live = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/donors/stats'))->get_data();

        $request = new WP_REST_Request('GET', '/gratora/v1/admin/donors/stats');
        $request->set_param('trashed', 'only');
        $bin = rest_do_request($request)->get_data();

        $this->assertSame(2, (int) $live['total_count'], 'the binned donor left the live headcount');
        $this->assertSame(1, (int) $bin['total_count'], 'and is the whole of the bin');
    }

    /** The screen cannot explain a refusal it was only handed a boolean for. */
    public function test_the_row_carries_the_gates_own_words(): void
    {
        $this->asRoleWith([self::VIEW, self::EDIT]);
        $hasMoney = $this->signup('reason');
        $this->paidDonation((int) $hasMoney->id);

        $rows = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/donors'))->get_data();

        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === (int) $hasMoney->id) {
                $row = $r;
                break;
            }
        }

        $this->assertNotNull($row, 'the donor is on the live list');
        $this->assertFalse($row['deletable']);
        $this->assertNotNull($row['undeletable_reason'], 'and the row says what keeps them');
    }
}
