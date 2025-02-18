<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function test_resolves_a_bound_service(): void
    {
        $c = new Container();
        $c->bind('greeter', fn () => new stdClass());

        $a = $c->get('greeter');
        $this->assertInstanceOf(stdClass::class, $a);
    }

    public function test_returns_the_same_instance_on_subsequent_resolutions(): void
    {
        $c = new Container();
        $c->bind('greeter', fn () => new stdClass());

        $a = $c->get('greeter');
        $b = $c->get('greeter');

        $this->assertSame($a, $b);
    }

    public function test_instance_short_circuits_factory(): void
    {
        $c = new Container();
        $obj = new stdClass();
        $c->instance('preset', $obj);

        $this->assertSame($obj, $c->get('preset'));
    }

    public function test_factory_receives_the_container_for_collaborator_resolution(): void
    {
        $c = new Container();
        $c->bind('leaf', fn () => new stdClass());
        $c->bind('root', fn (Container $c) => (object) ['leaf' => $c->get('leaf')]);

        $root = $c->get('root');

        $this->assertSame($c->get('leaf'), $root->leaf);
    }

    public function test_has_reports_bound_and_instance_entries(): void
    {
        $c = new Container();
        $this->assertFalse($c->has('x'));

        $c->bind('x', fn () => new stdClass());
        $this->assertTrue($c->has('x'));

        $c->instance('y', new stdClass());
        $this->assertTrue($c->has('y'));
    }

    public function test_missing_binding_throws(): void
    {
        $c = new Container();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no binding registered for missing');
        $c->get('missing');
    }

    public function test_rebinding_replaces_factory_and_clears_cached_instance(): void
    {
        $c = new Container();
        $c->bind('greeter', fn () => (object) ['name' => 'first']);
        $first = $c->get('greeter');

        $c->bind('greeter', fn () => (object) ['name' => 'second']);
        $second = $c->get('greeter');

        $this->assertSame('first', $first->name);
        $this->assertSame('second', $second->name);
        $this->assertNotSame($first, $second);
    }
}
