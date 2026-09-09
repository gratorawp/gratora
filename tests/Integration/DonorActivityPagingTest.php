<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\Event;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * occurred_at is second-precision and one donation writes several events inside
 * one second, so a page boundary that falls in the middle of a tie has nothing
 * stable to sort on: the same row comes back on both pages and another is never
 * returned at all.
 */
final class DonorActivityPagingTest extends IntegrationTestCase
{
    private int $donorId = 0;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('paging-' . uniqid() . '@example.test', ['first_name' => 'Ada']);
        $this->donorId = (int) $donor->id;

        $this->ids = [];
        foreach (range(1, 60) as $n) {
            $e = Event::make();
            $e->type        = 'donor.note_added';
            $e->donor_id    = $this->donorId;
            $e->occurred_at = '2026-04-06 09:00:00';
            $e->payload     = ['n' => $n];
            $e->save();
            $this->ids[] = (int) $e->id;
        }
    }

    /** @return list<int> */
    private function page(int $page, string $order): array
    {
        $req = new WP_REST_Request('GET', '/gratora/v1/admin/donors/' . $this->donorId . '/events');
        $req->set_param('per_page', 10);
        $req->set_param('page', $page);
        $req->set_param('order', $order);

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $data  = (array) $res->get_data();
        $items = (array) ($data['items'] ?? $data);

        return array_map(static fn (array $e): int => (int) $e['id'], $items);
    }

    public function test_the_newest_first_pages_read_newest_first(): void
    {
        $expected = array_reverse($this->ids);

        $this->assertSame(array_slice($expected, 0, 10), $this->page(1, 'desc'));
        $this->assertSame(array_slice($expected, 10, 10), $this->page(2, 'desc'));
    }

    public function test_the_oldest_first_pages_read_the_other_way_round(): void
    {
        $this->assertSame(array_slice($this->ids, 0, 10), $this->page(1, 'asc'));
        $this->assertSame(array_slice($this->ids, 10, 10), $this->page(2, 'asc'));
    }

    public function test_every_event_is_returned_exactly_once(): void
    {
        foreach (['asc', 'desc'] as $order) {
            $seen = [];
            foreach (range(1, 6) as $page) {
                $seen = array_merge($seen, $this->page($page, $order));
            }

            $this->assertCount(60, array_unique($seen), "{$order}: a row was shown twice");
            $this->assertEqualsCanonicalizing($this->ids, $seen, "{$order}: a row was never shown");
        }
    }
}
