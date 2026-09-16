<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Closure;
use Gratora\Foundation\Plugin;
use ReflectionFunction;

/**
 * An add-on can stay active while core is switched off and on again. Its
 * routes are attached by its module, which boots only on a request where core
 * was loaded at plugins_loaded, and the activation request is not one. Unless
 * a later request stores them, its pages 404 until someone saves permalinks.
 */
final class RewriteRulesAfterActivationTest extends IntegrationTestCase
{
    private const ROUTE       = '^gratora-rewrite-probe/?$';
    private const LATER_ROUTE = '^gratora-rewrite-probe-later/?$';

    private string $structure = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->structure = (string) get_option('permalink_structure');
    }

    protected function tearDown(): void
    {
        global $wp_rewrite;

        unset($wp_rewrite->extra_rules_top[self::ROUTE], $wp_rewrite->extra_rules_top[self::LATER_ROUTE]);
        $this->set_permalink_structure($this->structure);

        parent::tearDown();
    }

    public function test_the_first_request_after_activation_stores_routes_the_activation_missed(): void
    {
        Plugin::onPluginActivated();
        $this->set_permalink_structure('/%postname%/');

        $this->assertArrayNotHasKey(self::ROUTE, $this->storedRules(), 'precondition: nothing stored so far has the route');

        add_rewrite_rule(self::ROUTE, 'index.php?pagename=probe', 'top');
        $this->fireCoreWpLoaded();

        $this->assertArrayHasKey(self::ROUTE, $this->storedRules());
        $this->assertNotSame('1', get_option(Plugin::OPT_REWRITE_RULES_PENDING));
    }

    public function test_later_requests_neither_flush_again_nor_query_for_the_marker(): void
    {
        Plugin::onPluginActivated();
        $this->set_permalink_structure('/%postname%/');
        add_rewrite_rule(self::ROUTE, 'index.php?pagename=probe', 'top');
        $this->fireCoreWpLoaded();

        $this->assertArrayHasKey(self::ROUTE, $this->storedRules(), 'precondition: the first request flushed');

        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete(Plugin::OPT_REWRITE_RULES_PENDING, 'options');

        $asked  = [];
        $record = static function (string $query) use (&$asked): string {
            if (str_contains($query, Plugin::OPT_REWRITE_RULES_PENDING)) {
                $asked[] = $query;
            }

            return $query;
        };

        add_rewrite_rule(self::LATER_ROUTE, 'index.php?pagename=later', 'top');
        add_filter('query', $record);
        try {
            $this->fireCoreWpLoaded();
        } finally {
            remove_filter('query', $record);
        }

        $this->assertArrayNotHasKey(self::LATER_ROUTE, $this->storedRules());
        $this->assertSame([], $asked, 'a request with a cold option cache reads the marker from the autoloaded set');
    }

    /** @return array<string, string> */
    private function storedRules(): array
    {
        return (array) get_option('rewrite_rules', []);
    }

    /**
     * The test bootstrap hangs a full activation and a table truncate on
     * wp_loaded, so only the callbacks Plugin declares are left standing.
     */
    private function fireCoreWpLoaded(): void
    {
        global $wp_filter;

        $core    = realpath(dirname(__DIR__, 2) . '/src/Foundation/Plugin.php');
        $removed = [];

        foreach (($wp_filter['wp_loaded']->callbacks ?? []) as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if ($fn instanceof Closure && realpath((string) (new ReflectionFunction($fn))->getFileName()) === $core) {
                    continue;
                }

                $removed[] = [$fn, $priority, $callback['accepted_args']];
                remove_action('wp_loaded', $fn, $priority);
            }
        }

        try {
            do_action('wp_loaded');
        } finally {
            foreach ($removed as [$fn, $priority, $args]) {
                add_action('wp_loaded', $fn, $priority, $args);
            }
        }
    }
}
