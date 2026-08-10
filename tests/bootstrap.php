<?php
/**
 * PHPUnit bootstrap - pure unit tests, NO WordPress loaded.
 *
 * Stubs only the WP functions our unit-tested code actually calls. Anything
 * needing real WP (REST routing, DB, async scheduler, mPDF font cache) goes
 * into the `integration` suite which boots WordPress properly.
 *
 * Rule of thumb: if you need to stub something gnarly to test a class, that
 * class probably needs an integration test, not a unit test.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/vendor-prefixed/autoload.php';

// In-memory option store for tests
if (! isset($GLOBALS['_dono_test_options'])) {
    $GLOBALS['_dono_test_options'] = [];
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['_dono_test_options'][$name] ?? $default;
    }
    function add_option(string $name, mixed $value, string $deprecated = '', bool|string $autoload = true): bool
    {
        if (array_key_exists($name, $GLOBALS['_dono_test_options'])) return false;
        $GLOBALS['_dono_test_options'][$name] = $value;
        return true;
    }
    function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
    {
        $GLOBALS['_dono_test_options'][$name] = $value;
        return true;
    }
    function delete_option(string $name): bool
    {
        unset($GLOBALS['_dono_test_options'][$name]);
        return true;
    }
}

// Hook stubs: no-op, tests assert behaviour, not hook firing
if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool { return true; }
    function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool { return true; }
    function do_action(string $hook, mixed ...$args): void {}
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
}

// Salt / cache: deterministic stubs for tests
if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return str_repeat('A', 64) . $scheme;
    }
}
if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool { return true; }
}

// Esc helpers: pass-through; tests aren't checking HTML escaping
if (! function_exists('esc_html')) {
    function esc_html(string $s): string { return $s; }
    function esc_attr(string $s): string { return $s; }
    function esc_url(string $s): string { return $s; }
}

// i18n stubs: return the string untouched (no translations loaded)
if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
    function _e(string $text, string $domain = 'default'): void { echo $text; }
    function _x(string $text, string $ctx, string $domain = 'default'): string { return $text; }
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
    function esc_html_e(string $text, string $domain = 'default'): void { echo $text; }
}

// is_email
if (! function_exists('is_email')) {
    function is_email(string $email): false|string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
}

// Minimal WP_REST_Request stand-in - we don't exercise REST routing in unit
// tests, but several interfaces type-hint against it.
if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request {}
}

// Action Scheduler stubs for AsyncDispatcher unit tests.
if (! function_exists('as_enqueue_async_action')) {
    $GLOBALS['_dono_as_calls'] = [];
    $GLOBALS['_dono_as_has_scheduled'] = false;
    function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int
    {
        $GLOBALS['_dono_as_calls'][] = ['func' => 'as_enqueue_async_action', 'args' => [$hook, $args, $group]];
        return 1;
    }
    function as_schedule_single_action(int $ts, string $hook, array $args = [], string $group = ''): int
    {
        $GLOBALS['_dono_as_calls'][] = ['func' => 'as_schedule_single_action', 'args' => [$ts, $hook, $args, $group]];
        return 1;
    }
    function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
    {
        $GLOBALS['_dono_as_calls'][] = ['func' => 'as_has_scheduled_action', 'args' => [$hook, $args, $group]];
        return $GLOBALS['_dono_as_has_scheduled'];
    }
    function as_schedule_recurring_action(int $ts, int $interval, string $hook, array $args = [], string $group = ''): int
    {
        $GLOBALS['_dono_as_calls'][] = ['func' => 'as_schedule_recurring_action', 'args' => [$ts, $interval, $hook, $args, $group]];
        return 1;
    }
}

// Transient stubs for rate-limiting tests.
if (! function_exists('get_transient')) {
    $GLOBALS['_dono_test_transients'] = [];
    function get_transient(string $key): mixed { return $GLOBALS['_dono_test_transients'][$key] ?? false; }
    function set_transient(string $key, mixed $value, int $expiration = 0): bool { $GLOBALS['_dono_test_transients'][$key] = $value; return true; }
}

// Reset option store between tests.
$GLOBALS['_dono_reset_options'] = function (): void {
    $GLOBALS['_dono_test_options'] = [];
    $GLOBALS['_dono_as_calls'] = [];
    $GLOBALS['_dono_as_has_scheduled'] = false;
    $GLOBALS['_dono_test_transients'] = [];
};
