<?php

declare(strict_types=1);

namespace Gratora\Foundation\Upgrade;

/**
 * One request migrates; the rest of the burst carry on serving.
 *
 * The wp_loaded gate reads a stamp that is only written once the migration
 * finishes, so on a busy site every request arriving in the meantime ran the
 * whole schema pass again: concurrent DDL against the same tables, with
 * dbDelta swallowing whatever the losers hit.
 *
 * @since 1.0.0
 */
final class MigrationLock
{
    public const OPTION = 'gratora_migration_lock';

    /** Long enough for a schema pass, short enough that a fatal is not fatal. */
    private const TTL = 60;

    /**
     * INSERT IGNORE rather than get_option/add_option: only the database can
     * settle a race, and a persistent object cache would answer a read from a
     * copy the winner never touched.
     *
     * @since 1.0.0
     */
    public static function claim(): bool
    {
        global $wpdb;

        $rows = self::insert();

        // A driver without INSERT IGNORE reports an error rather than a count,
        // and a stack this exotic still has to be able to migrate.
        if ($rows === false) {
            return true;
        }

        if ($rows > 0) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the point is to bypass every cache.
        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            self::OPTION
        ));

        if ($held > 0 && (time() - $held) < self::TTL) {
            return false;
        }

        // Stale: whoever held it died mid-pass.
        self::release();

        return self::insert() !== 0;
    }

    /** @since 1.0.0 */
    public static function release(): void
    {
        delete_option(self::OPTION);
    }

    private static function insert(): int|bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the point is to bypass every cache.
        return $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::OPTION,
            (string) time()
        ));
    }
}
