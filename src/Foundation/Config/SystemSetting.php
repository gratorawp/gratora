<?php

declare(strict_types=1);

namespace Dono\Foundation\Config;

use Dono\Vendor\Queryable\DB;
use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Persistent install-level settings stored in dono_system_settings.
 *
 * @version 1.0.0
 */
final class SystemSetting extends Model
{
    protected string $table = 'dono_system_settings';
    protected string $version = '1.0.0';
    protected string $primaryKey = 'setting_key';

    public string $setting_key;
    public string $setting_value = '';
    public string $updated_at;

    /** Return a setting value by key, or null if absent. */
    public static function read(string $key): ?string
    {
        $row = DB::table('dono_system_settings')
            ->where('setting_key', $key)
            ->select('setting_value')
            ->get();

        if (! is_array($row)) return null;
        return isset($row['setting_value']) ? (string) $row['setting_value'] : null;
    }

    /** Upsert a setting by key. */
    public static function write(string $key, string $value): void
    {
        $now = gmdate('Y-m-d H:i:s');
        DB::table('dono_system_settings')->upsert(
            [
                'setting_key'   => $key,
                'setting_value' => $value,
                'updated_at'    => $now,
            ],
            ['setting_key'],
            ['setting_value', 'updated_at'],
        );
    }

    /** Return whether a setting row exists for $key. */
    public static function exists(string $key): bool
    {
        return DB::table('dono_system_settings')
            ->where('setting_key', $key)
            ->exists();
    }

    /** Delete the setting row for $key. */
    public static function forget(string $key): void
    {
        DB::table('dono_system_settings')
            ->where('setting_key', $key)
            ->delete();
    }
}

SystemSetting::schema(function (Table $t): void {
    $t->string('setting_key', 64)->primary();
    $t->longText('setting_value');
    $t->datetime('updated_at');
});
