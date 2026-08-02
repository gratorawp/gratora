<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Rest\ControllerRegistry;

final class RestAggregationTest extends IntegrationTestCase
{
    public function test_an_add_on_controller_registers_via_hook_without_touching_the_constructor(): void
    {
        $fake = new class {
            public function registerRoutes(): void
            {
                register_rest_route('dono-addon/v1', '/ping', [
                    'methods'             => 'GET',
                    'callback'            => static fn () => ['pong' => true],
                    'permission_callback' => '__return_true',
                ]);
            }
        };

        add_action('dono.rest.register', static function (ControllerRegistry $r) use ($fake): void {
            $r->add($fake);
        });

        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/dono-addon/v1/ping', $routes, 'the add-on route resolves');
        $this->assertArrayHasKey('/dono/v1/admin/commands', $routes, 'core route unchanged');

        remove_all_actions('dono.rest.register');
    }
}
