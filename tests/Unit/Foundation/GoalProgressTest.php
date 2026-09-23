<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Foundation;

use Gratora\Foundation\Helpers\GoalProgress;
use PHPUnit\Framework\TestCase;

/** @since 1.0.0 */
final class GoalProgressTest extends TestCase
{
    public function test_a_campaign_past_its_target_says_how_far(): void
    {
        $this->assertSame(112, GoalProgress::percent(894000, 800000));
        $this->assertSame(1957, GoalProgress::percent(4892, 250));
    }

    public function test_one_short_of_its_target_is_unchanged(): void
    {
        $this->assertSame(89, GoalProgress::percent(446750, 500000));
        $this->assertSame(3, GoalProgress::percent(22500, 800000));
    }

    public function test_no_target_is_no_progress_rather_than_a_division(): void
    {
        $this->assertSame(0, GoalProgress::percent(5000, 0));
        $this->assertSame(0, GoalProgress::percent(5000, -1));
    }

    public function test_the_bar_stops_at_the_end_of_its_track(): void
    {
        $this->assertSame(100, GoalProgress::barWidth(1957));
        $this->assertSame(100, GoalProgress::barWidth(101));
        $this->assertSame(89, GoalProgress::barWidth(89));
    }

    /** Net refunds can take a campaign below nothing, and a width cannot go there. */
    public function test_the_bar_does_not_run_backwards(): void
    {
        $this->assertSame(0, GoalProgress::barWidth(-5));
    }
}
