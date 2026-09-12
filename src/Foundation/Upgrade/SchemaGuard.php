<?php

declare(strict_types=1);

namespace Gratora\Foundation\Upgrade;

use Gratora\Analytics\ErrorLog;
use Gratora\Foundation\Plugin;
use Gratora\Vendor\Queryable\Model;
use Gratora\Vendor\Queryable\Schema\Table;
use ReflectionClass;
use ReflectionProperty;

/**
 * Verify tables and columns before stamping the schema version; dbDelta may silently fail and
 * the stamp suppresses retries.
 *
 * @since 1.0.0
 */
final class SchemaGuard
{
    public const OPTION = 'gratora_db_version';

    /**
     * Unprefixed names of the tables a migration should have created and did not.
     *
     * Asked of the database rather than of the migrator: dbDelta swallows the
     * failure, so its return value says a table exists whether or not one does.
     *
     * @return string[]
     * @since 1.0.0
     */
    public static function missingTables(): array
    {
        global $wpdb;

        $missing = [];

        foreach (array_keys(self::expected()) as $table) {
            $full  = $wpdb->prefix . $table;
            $found = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($full))
            );

            if ((string) $found !== $full) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Columns a migration should have added and did not, keyed by unprefixed
     * table. Asked of the database for the same reason the tables are: dbDelta
     * reports success for an ALTER it never ran.
     *
     * @return array<string, list<string>>
     * @since 1.0.0
     */
    public static function missingColumns(): array
    {
        global $wpdb;

        $missing = [];

        foreach (self::expected() as $table => $columns) {
            if ($columns === []) {
                continue;
            }

            $full = $wpdb->prefix . $table;
            $rows = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($full) . '`');
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $have = array_map('strtolower', $rows);
            $gone = array_values(array_filter(
                $columns,
                static fn (string $c): bool => ! in_array(strtolower($c), $have, true)
            ));

            if ($gone !== []) {
                $missing[$table] = $gone;
            }
        }

        return $missing;
    }

    /**
     * Stamp the schema version, unless a table is missing.
     *
     * Leaving the option alone is the whole recovery path: the wp_loaded gate
     * sees a version behind GRATORA_DB_VERSION and migrates again next request.
     *
     * @return bool true when the stamp was written
     * @since 1.0.0
     */
    public static function stampWhenComplete(): bool
    {
        $missing = self::missingTables();

        if ($missing !== []) {
            ErrorLog::toDebugLog(
                'schema incomplete, version not stamped. Missing tables: ' . implode(', ', $missing)
            );

            return false;
        }

        $columns = self::missingColumns();

        if ($columns !== []) {
            $named = [];
            foreach ($columns as $table => $cols) {
                $named[] = $table . '.' . implode(', ' . $table . '.', $cols);
            }

            ErrorLog::toDebugLog(
                'schema incomplete, version not stamped. Missing columns: ' . implode(', ', $named)
            );

            return false;
        }

        update_option(self::OPTION, GRATORA_DB_VERSION, false);

        return true;
    }

    /** @since 1.0.0 */
    public static function registerNotice(): void
    {
        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    /**
     * Names the tables that are missing. Without it the site is a white screen
     * and a support ticket that says "the plugin does not work".
     *
     * @since 1.0.0
     */
    public static function renderNotice(): void
    {
        // Whoever switched the plugin on is who has to ask the host for the
        // grant, and manage_gratora may never have been applied.
        if (! current_user_can('manage_options')) {
            return;
        }

        if (get_option(self::OPTION) === GRATORA_DB_VERSION) {
            return;
        }

        $missing = self::missingTables();
        if ($missing === []) {
            return;
        }

        global $wpdb;
        $names = implode(', ', array_map(static fn (string $t): string => $wpdb->prefix . $t, $missing));

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
            esc_html__('Gratora could not create its database tables.', 'gratora-donation-platform'),
            esc_html__('The plugin cannot run until they exist. This usually means the database user is not allowed to create tables, or the host caps how many a site may have. Ask your host to grant CREATE, then reload this page: Gratora retries on every request.', 'gratora-donation-platform'),
            esc_html($names)
        );
    }

    /**
     * Every table the registered modules migrate, unprefixed, mapped to the
     * columns their schema declares. An empty list means existence is all this
     * can check: a model with no registered closure, or a meta table, whose
     * shape the builder compiles separately.
     *
     * @return array<string, list<string>>
     * @since 1.0.0
     */
    private static function expected(): array
    {
        $tables = [];

        foreach (Plugin::instance()->modules->allMigrations() as $model) {
            if (! class_exists($model)) {
                continue;
            }

            // A model this cannot read is skipped rather than thrown out of.
            // The registry is open to third-party modules through
            // gratora.modules.register, and the callers are a gate that has to
            // survive a schema it cannot trust and an admin notice: neither is
            // worth white-screening a site over.
            try {
                $reflection = new ReflectionClass($model);
                $instance   = $reflection->newInstance();

                $property = $reflection->getProperty('table');
                $property->setAccessible(true);
                $name = (string) $property->getValue($instance);

                if ($name === '') {
                    continue;
                }

                $tables[$name] = self::declaredColumns($model, $instance, $reflection);

                // A model that declares meta gets a second table from the same
                // migration, named the way Table::compileMetaTable names it.
                $meta = $reflection->getMethod('meta');
                $meta->setAccessible(true);
                $config = (array) $meta->invoke($instance);

                if ($config !== []) {
                    $metaName = empty($config['table']) ? $name . '_meta' : (string) $config['table'];
                    $tables[$metaName] = [];
                }
            } catch (\Throwable $e) {
                ErrorLog::toDebugLog('schema guard skipped ' . $model . ': ' . $e->getMessage());
            }
        }

        return $tables;
    }

    /**
     * The columns a model's schema closure declares.
     *
     * Reached by reflection because the closure registry is private static on
     * the vendored Model. A queryable release that renames it drops this back
     * to the existence-only check rather than breaking the gate.
     *
     * @return list<string>
     */
    private static function declaredColumns(string $model, object $instance, ReflectionClass $reflection): array
    {
        global $wpdb;

        try {
            $schemas = new ReflectionProperty(Model::class, 'schemas');
            $schemas->setAccessible(true);
            $callback = ($schemas->getValue()[$model] ?? null);

            if (! $callback instanceof \Closure) {
                return [];
            }

            $meta = $reflection->getMethod('meta');
            $meta->setAccessible(true);

            $table = new Table(
                $wpdb->charset ?: 'utf8mb4',
                $wpdb->collate ?: 'utf8mb4_unicode_ci',
                (array) $meta->invoke($instance)
            );
            $callback($table);

            $names = [];
            foreach ($table->getColumns() as $column) {
                $name = (string) ($column->getDefinition()['name'] ?? '');
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return $names;
        } catch (\Throwable $e) {
            ErrorLog::toDebugLog('schema guard could not read columns for ' . $model . ': ' . $e->getMessage());

            return [];
        }
    }
}
