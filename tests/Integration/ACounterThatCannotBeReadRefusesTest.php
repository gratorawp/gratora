<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\AntiSpamGuard;
use Gratora\Foundation\Plugin;

/**
 * peek() answers a screen, peekStrict() answers a gate.
 *
 * A limiter built on peek() reads a database it cannot query as a caller who
 * has spent nothing, which is the one moment the ceiling is load bearing.
 */
final class ACounterThatCannotBeReadRefusesTest extends IntegrationTestCase
{
    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    public function test_an_unspent_counter_reads_as_zero(): void
    {
        $this->assertSame(0, $this->guard()->peekStrict('gratora_probe_' . uniqid(), HOUR_IN_SECONDS));
    }

    public function test_it_counts_what_hit_wrote(): void
    {
        $guard = $this->guard();
        $base  = 'gratora_probe_' . uniqid();

        $guard->hit($base, HOUR_IN_SECONDS);
        $guard->hit($base, HOUR_IN_SECONDS);

        $this->assertSame(2, $guard->peekStrict($base, HOUR_IN_SECONDS));
        $this->assertSame($guard->peek($base, HOUR_IN_SECONDS), $guard->peekStrict($base, HOUR_IN_SECONDS));
    }

    public function test_a_read_the_database_refuses_is_not_a_zero(): void
    {
        global $wpdb;

        $guard = $this->guard();
        $base  = 'gratora_probe_' . uniqid();

        $break = static fn ($sql) => str_contains((string) $sql, $base)
            ? 'SELECT option_value FROM gratora_no_such_table WHERE 1 = 0'
            : $sql;

        $showing    = $wpdb->hide_errors();
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $break);

        $verdict = $guard->peekStrict($base, HOUR_IN_SECONDS);

        remove_filter('query', $break);
        $wpdb->suppress_errors($suppressed);
        if ($showing) {
            $wpdb->show_errors();
        }

        $this->assertNull($verdict, 'a count the site cannot read is not a count of nothing');
    }

    /** An add-on metering its own callers has to be able to stay under this. */
    public function test_the_site_wide_ceiling_is_readable(): void
    {
        $this->assertGreaterThan(0, AntiSpamGuard::ipMax());
        $this->assertGreaterThan(0, AntiSpamGuard::ipWindow());
    }
}
