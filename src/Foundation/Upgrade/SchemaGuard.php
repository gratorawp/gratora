<?php

declare(strict_types=1);

namespace Dono\Foundation\Upgrade;

use Dono\Analytics\ErrorLog;
use Dono\Foundation\Plugin;
use ReflectionClass;

/**
 * Confirms every dono_* table is really there before the schema version is stamped.
 *
 * dbDelta reports nothing at all when a CREATE is refused, and plenty of
 * managed and shared hosts refuse one: restricted grants, a table-count quota,
 * a row-size or collation limit. The stamped version is the only thing the
 * wp_loaded gate reads to decide whether to migrate again, so writing it on a
 * migration that created nothing is what turns a recoverable install into a
 * site that is broken for good.
 *
 * @since 1.0.0
 */
final class SchemaGuard
{
    public const OPTION = 'dono_db_version';

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

        foreach (self::expectedTables() as $table) {
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
     * Stamp the schema version, unless a table is missing.
     *
     * Leaving the option alone is the whole recovery path: the wp_loaded gate
     * sees a version behind DONO_DB_VERSION and migrates again next request.
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

        update_option(self::OPTION, DONO_DB_VERSION, false);

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
        // grant, and manage_dono may never have been applied.
        if (! current_user_can('manage_options')) {
            return;
        }

        if (get_option(self::OPTION) === DONO_DB_VERSION) {
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
            esc_html__('Dono could not create its database tables.', 'dono-fundraising-platform'),
            esc_html__('The plugin cannot run until they exist. This usually means the database user is not allowed to create tables, or the host caps how many a site may have. Ask your host to grant CREATE, then reload this page: Dono retries on every request.', 'dono-fundraising-platform'),
            esc_html($names)
        );
    }

    /**
     * Every table the registered modules migrate, unprefixed.
     *
     * @return string[]
     * @since 1.0.0
     */
    private static function expectedTables(): array
    {
        $tables = [];

        foreach (Plugin::instance()->modules->allMigrations() as $model) {
            if (! class_exists($model)) {
                continue;
            }

            // A model this cannot read is skipped rather than thrown out of.
            // The registry is open to third-party modules through
            // dono.modules.register, and the callers are a gate that has to
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

                $tables[] = $name;

                // A model that declares meta gets a second table from the same
                // migration, named the way Table::compileMetaTable names it.
                $meta = $reflection->getMethod('meta');
                $meta->setAccessible(true);
                $config = (array) $meta->invoke($instance);

                if ($config !== []) {
                    $tables[] = empty($config['table'])
                        ? $name . '_meta'
                        : (string) $config['table'];
                }
            } catch (\Throwable $e) {
                ErrorLog::toDebugLog('schema guard skipped ' . $model . ': ' . $e->getMessage());
            }
        }

        return array_values(array_unique($tables));
    }
}
