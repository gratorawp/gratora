<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\AntiSpamGuard;
use Gratora\Foundation\Maintenance\TransientGc;
use Gratora\Foundation\Plugin;

/**
 * Counters live in wp_options for atomic increments; object-cache transient APIs cannot delete
 * them.
 */
final class TransientGcObjectCacheTest extends IntegrationTestCase
{
    private ?bool $wasExternal = null;

    protected function tearDown(): void
    {
        if ($this->wasExternal !== null) {
            wp_using_ext_object_cache($this->wasExternal);
            $this->wasExternal = null;
        }

        parent::tearDown();
    }

    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    /** Counter rows for $base, as hit() writes them, aged past their expiry. */
    private function seedExpiredCounter(string $base): string
    {
        global $wpdb;

        $this->guard()->hit($base, 900);

        $name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
            '_transient_' . $base . '%'
        ));
        $this->assertNotSame('', $name, 'hit() must have written a counter row');

        $key = substr($name, strlen('_transient_'));
        $wpdb->update(
            $wpdb->options,
            ['option_value' => (string) (time() - 100)],
            ['option_name' => '_transient_timeout_' . $key]
        );

        return $key;
    }

    private function rowsFor(string $key): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
            '_transient_' . $key,
            '_transient_timeout_' . $key
        ));
    }

    public function test_expired_counters_are_reclaimed_with_an_object_cache_present(): void
    {
        $this->wasExternal = wp_using_ext_object_cache(true);

        $key = $this->seedExpiredCounter('gratora_gcprobe_' . bin2hex(random_bytes(4)));
        $this->assertSame(2, $this->rowsFor($key), 'both rows exist before the run');

        (new TransientGc(Plugin::instance()->container->get(\Gratora\Async\AsyncDispatcher::class)))->run();

        $this->assertSame(
            0,
            $this->rowsFor($key),
            'a counter row written by raw SQL must be reclaimed even when delete_transient cannot reach it'
        );
    }

    public function test_expired_counters_are_reclaimed_without_an_object_cache(): void
    {
        $this->wasExternal = wp_using_ext_object_cache(false);

        $key = $this->seedExpiredCounter('gratora_gcprobe_' . bin2hex(random_bytes(4)));

        (new TransientGc(Plugin::instance()->container->get(\Gratora\Async\AsyncDispatcher::class)))->run();

        $this->assertSame(0, $this->rowsFor($key));
    }

    public function test_a_live_counter_is_left_alone(): void
    {
        $this->wasExternal = wp_using_ext_object_cache(true);

        $base = 'gratora_gclive_' . bin2hex(random_bytes(4));
        $this->guard()->hit($base, 900);
        $key = $base . '_' . (int) floor(time() / 900);

        (new TransientGc(Plugin::instance()->container->get(\Gratora\Async\AsyncDispatcher::class)))->run();

        $this->assertSame(2, $this->rowsFor($key), 'an unexpired limit must survive the sweep');
        $this->assertSame(1, $this->guard()->peek($base, 900), 'and must still read as spent');
    }
}
