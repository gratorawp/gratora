<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The consumer of this contract is a separately released add-on that reads the
 * number by name before it serves anything, so every reference here is a string:
 * a renamed class, a moved namespace or a renamed constant is the same outage to
 * that add-on as a deleted one, and a symbol-level test would follow the rename
 * and stay green through it.
 *
 * @since 1.0.0
 */
final class EmbedContractTest extends TestCase
{
    private const FQCN = 'Gratora\\Forms\\Rendering\\EmbedContract';

    public function test_an_addon_resolves_the_contract_by_name(): void
    {
        $this->assertTrue(
            class_exists(self::FQCN),
            self::FQCN . ' is the name an add-on hard-codes; moving or renaming it breaks every installed copy.'
        );
        $this->assertTrue(defined(self::FQCN . '::VERSION'));
    }

    public function test_the_version_is_a_public_integer_an_addon_can_compare(): void
    {
        $constant = (new ReflectionClass(self::FQCN))->getReflectionConstant('VERSION');

        $this->assertNotFalse($constant);
        $this->assertTrue($constant->isPublic());
        $this->assertIsInt($constant->getValue());
        $this->assertSame(1, $constant->getValue());
    }

    /**
     * The number is truthful only while it describes a declaration. Methods on
     * here are surface an add-on can couple to that no version bump announces.
     */
    public function test_the_contract_declares_and_does_nothing(): void
    {
        $class = new ReflectionClass(self::FQCN);

        $this->assertTrue($class->isFinal());
        $this->assertSame([], array_map(static fn ($m): string => $m->getName(), $class->getMethods()));
        $this->assertSame([], array_map(static fn ($p): string => $p->getName(), $class->getProperties()));
        $this->assertSame(['VERSION'], array_keys($class->getConstants()));
    }
}
