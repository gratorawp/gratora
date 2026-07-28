<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Rest;

use Dono\Rest\Paging;
use PHPUnit\Framework\TestCase;

/**
 * `page=9223372036854775807` overflowed `($page - 1) * $perPage` into a float,
 * and QueryBuilder::offset() is typed int, so every admin list route died on an
 * uncaught TypeError. The schemas said `minimum: 1` and no maximum, and six
 * controllers each read the value for themselves.
 */
final class PagingTest extends TestCase
{
    public function test_an_overflowing_page_is_clamped_rather_than_left_to_become_a_float(): void
    {
        $page = Paging::page(PHP_INT_MAX);

        $this->assertSame(Paging::MAX_PAGE, $page);
        $this->assertIsInt(($page - 1) * 100, 'the offset stays an integer');
    }

    public function test_the_clamp_leaves_room_for_any_real_list(): void
    {
        $this->assertGreaterThanOrEqual(1000000, Paging::MAX_PAGE);
        // 100 is the largest per_page any list route accepts.
        $this->assertIsInt((Paging::MAX_PAGE - 1) * 100);
    }

    public function test_ordinary_pages_pass_through(): void
    {
        $this->assertSame(1, Paging::page(1));
        $this->assertSame(7, Paging::page(7));
        $this->assertSame(7, Paging::page('7'), 'query strings arrive as strings');
    }

    public function test_nonsense_falls_back_to_the_first_page(): void
    {
        $this->assertSame(1, Paging::page(null));
        $this->assertSame(1, Paging::page(''));
        $this->assertSame(1, Paging::page('abc'));
        $this->assertSame(1, Paging::page(0));
        $this->assertSame(1, Paging::page(-5), 'a negative page would be a negative offset');
    }
}
