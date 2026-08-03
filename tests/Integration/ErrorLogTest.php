<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\Event;
use Dono\Analytics\EventRetention;
use Dono\Async\AsyncDispatcher;
use Dono\Foundation\Plugin;

/**
 * Failures are recorded where the site owner can reach them.
 */
final class ErrorLogTest extends IntegrationTestCase
{
    private function errors(): array
    {
        return Event::query()
            ->whereLike('type', ErrorLog::PREFIX . '%')
            ->orderBy('id', 'DESC')
            ->getAll();
    }

    public function test_a_failure_lands_in_the_event_log(): void
    {
        ErrorLog::record('gateway.stripe', 'Webhook auto-provision failed');

        $row = $this->errors()[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('error.gateway.stripe', $row->type);
        $this->assertSame('Webhook auto-provision failed', $row->payload['message']);
    }

    public function test_context_ids_become_columns_so_an_error_filters_like_any_event(): void
    {
        ErrorLog::record('recurring.cancel', 'Gateway refused', ['recurring_plan_id' => 4242]);

        $row = $this->errors()[0];

        $this->assertSame(4242, (int) $row->recurring_plan_id);
    }

    public function test_extra_context_is_kept_beside_the_message(): void
    {
        ErrorLog::record('upgrade', 'Routine stopped', ['routine' => '2026_08_example']);

        $payload = $this->errors()[0]->payload;

        $this->assertSame('Routine stopped', $payload['message']);
        $this->assertSame('2026_08_example', $payload['routine']);
    }

    public function test_the_source_is_reduced_to_a_safe_slug(): void
    {
        ErrorLog::record('Gateway/PayPal!!', 'Something');

        $this->assertSame('error.gatewaypaypal', $this->errors()[0]->type);
    }

    public function test_a_long_message_is_capped(): void
    {
        ErrorLog::record('command', str_repeat('x', 5000));

        $this->assertSame(1000, mb_strlen($this->errors()[0]->payload['message']));
    }

    public function test_errors_age_out_with_the_activity_log(): void
    {
        // Being events, the retention window the admin sets already covers
        // them; no second schedule and no second table.
        ErrorLog::record('command', 'Old failure');

        $id = (int) $this->errors()[0]->id;
        Event::query()->where('id', $id)->update(['occurred_at' => '2020-01-01 00:00:00']);

        (new EventRetention(Plugin::instance()->container->get(AsyncDispatcher::class)))->run();

        $this->assertNull(
            Event::query()->where('id', $id)->get(),
            'an error old enough to prune is pruned, like any other event'
        );
    }
}
