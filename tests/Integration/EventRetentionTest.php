<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\Event;
use Dono\Analytics\EventRetention;
use Dono\Foundation\Plugin;

/**
 * EventRetention prunes dono_events older than the retention window. The prune
 * now runs in bounded batches (re-enqueuing while a full batch returns) instead
 * of one unbounded DELETE, but the cutoff semantics must stay the same.
 */
final class EventRetentionTest extends IntegrationTestCase
{
    public function test_run_prunes_events_past_the_window_and_keeps_recent(): void
    {
        update_option('dono_privacy', ['event_retention_days' => 730]);

        $this->seedEvent('old',    gmdate('Y-m-d H:i:s', time() - 800 * 86400));
        $this->seedEvent('recent', gmdate('Y-m-d H:i:s', time() - 10 * 86400));

        $this->retention()->run();

        $this->assertSame(0, (int) Event::query()->where('type', 'old')->count(), 'events past the window are pruned');
        $this->assertSame(1, (int) Event::query()->where('type', 'recent')->count(), 'recent events are kept');
    }

    public function test_zero_retention_disables_pruning(): void
    {
        update_option('dono_privacy', ['event_retention_days' => 0]);

        $this->seedEvent('ancient', gmdate('Y-m-d H:i:s', time() - 5000 * 86400));

        $this->retention()->run();

        $this->assertSame(1, (int) Event::query()->where('type', 'ancient')->count(), 'retention 0 keeps everything');
    }

    private function retention(): EventRetention
    {
        return new EventRetention(
            Plugin::instance()->container->get(\Dono\Async\AsyncDispatcher::class)
        );
    }

    private function seedEvent(string $type, string $occurredAt): void
    {
        $e = Event::make();
        $e->type        = $type;
        $e->occurred_at = $occurredAt;
        $e->save();
    }
}
