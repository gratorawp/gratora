<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;
use ReflectionClass;
use ReflectionProperty;

/** Verify every model property has a migrated database column. */
final class SchemaIntegrityTest extends IntegrationTestCase
{
    public function test_every_model_property_has_a_backing_column(): void
    {
        $drift = [];

        foreach (Plugin::instance()->modules->allMigrations() as $cls) {
            if (! method_exists($cls, 'make')) {
                continue;
            }
            $table = $this->tableFor($cls);
            $cols  = $this->columnsFor($table);
            $this->assertNotEmpty($cols, "Table for {$cls} ({$table}) has no columns - did migration run?");

            $missing = array_values(array_diff($this->columnProps($cls), $cols));
            if ($missing !== []) {
                $drift[] = $cls . ': ' . implode(', ', $missing);
            }
        }

        $this->assertSame(
            [],
            $drift,
            "Model properties without a backing column (add them to the model's migration closure):\n" . implode("\n", $drift)
        );
    }

    private function tableFor(string $cls): string
    {
        $prop = new ReflectionProperty($cls, 'table');
        $prop->setAccessible(true);
        return self::$prefix . (string) $prop->getValue($cls::make());
    }

    /** @return list<string> */
    private function columnsFor(string $table): array
    {
        $rows = self::$wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A) ?: [];
        return array_map(static fn ($r) => (string) $r['Field'], $rows);
    }

    /** @return list<string> public, non-static instance properties (the column set). */
    private function columnProps(string $cls): array
    {
        $names = [];
        foreach ((new ReflectionClass($cls))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic()) {
                $names[] = $prop->getName();
            }
        }
        return $names;
    }
}
