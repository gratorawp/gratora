<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit\Async;

use FundKit\Async\AsyncDispatcher;
use PHPUnit\Framework\TestCase;

final class AsyncDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_fundkit_as_calls'] = [];
        $GLOBALS['_fundkit_as_has_scheduled'] = false;
        // The installed map is an option now, so it outlives a test that wrote it.
        unset($GLOBALS['_fundkit_test_options'][AsyncDispatcher::INSTALLED_OPTION]);
    }

    private function calls(string $func = ''): array
    {
        $all = $GLOBALS['_fundkit_as_calls'] ?? [];
        return $func === '' ? $all : array_values(array_filter($all, fn ($c) => $c['func'] === $func));
    }

    public function test_enqueue_delegates_to_action_scheduler(): void
    {
        $d = new AsyncDispatcher();
        $d->enqueue('fundkit.test.hook', ['key' => 'value']);

        $calls = $this->calls('as_enqueue_async_action');
        $this->assertCount(1, $calls);
        $this->assertSame('fundkit.test.hook', $calls[0]['args'][0]);
        $this->assertSame(['key' => 'value'], $calls[0]['args'][1]);
        $this->assertSame('fundkit', $calls[0]['args'][2]);
    }

    public function test_schedule_delegates_with_timestamp(): void
    {
        $d = new AsyncDispatcher();
        $d->schedule('fundkit.test.hook', 1700000000, ['id' => 5]);

        $calls = $this->calls('as_schedule_single_action');
        $this->assertCount(1, $calls);
        $this->assertSame(1700000000, $calls[0]['args'][0]);
        $this->assertSame('fundkit.test.hook', $calls[0]['args'][1]);
        $this->assertSame('fundkit', $calls[0]['args'][3]);
    }

    public function test_schedule_recurring_skips_when_already_scheduled(): void
    {
        $GLOBALS['_fundkit_as_has_scheduled'] = true;

        $d = new AsyncDispatcher();
        $d->scheduleRecurring('fundkit.test.hook', 86400);

        $this->assertCount(0, $this->calls('as_schedule_recurring_action'));
    }

    public function test_schedule_recurring_creates_when_not_scheduled(): void
    {
        $d = new AsyncDispatcher();
        $d->scheduleRecurring('fundkit.test.hook', 86400);

        $calls = $this->calls('as_schedule_recurring_action');
        $this->assertCount(1, $calls);
        $this->assertSame('fundkit.test.hook', $calls[0]['args'][2]);
        $this->assertSame(86400, $calls[0]['args'][1]);
        $this->assertSame('fundkit', $calls[0]['args'][4]);
    }

    public function test_group_is_always_fundkit(): void
    {
        $d = new AsyncDispatcher();
        $d->enqueue('hook1');
        $d->schedule('hook2', 1700000000);
        $d->scheduleRecurring('hook3', 3600);

        foreach ($this->calls() as $call) {
            $lastArg = end($call['args']);
            $this->assertSame('fundkit', $lastArg, "{$call['func']} group should be 'fundkit'");
        }
    }
}
