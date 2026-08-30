<?php

declare(strict_types=1);

namespace FundKit\Foundation\Config;

defined('ABSPATH') || exit;

use FundKit\Vendor\Queryable\DB;
use FundKit\Vendor\Queryable\Model;
use FundKit\Vendor\Queryable\Schema\Table;

/**
 * Persistent install-level settings stored in fundkit_system_settings.
 *
 * @since 1.0.0
 */
final class SystemSetting extends Model
{
    protected string $table = 'fundkit_system_settings';
    protected string $version = '1.0.0';
    protected string $primaryKey = 'setting_key';

    public string $setting_key;
    public string $setting_value = '';
    public string $updated_at;

    /** @since 1.0.0 */
    public static function read(string $key): ?string
    {
        $row = DB::table('fundkit_system_settings')
            ->where('setting_key', $key)
            ->select('setting_value')
            ->get();

        if (! is_array($row)) return null;
        return isset($row['setting_value']) ? (string) $row['setting_value'] : null;
    }

    /** @since 1.0.0 */
    public static function write(string $key, string $value): void
    {
        $now = gmdate('Y-m-d H:i:s');
        DB::table('fundkit_system_settings')->upsert(
            [
                'setting_key'   => $key,
                'setting_value' => $value,
                'updated_at'    => $now,
            ],
            ['setting_key'],
            ['setting_value', 'updated_at'],
        );
    }

    /** @since 1.0.0 */
    public static function exists(string $key): bool
    {
        return DB::table('fundkit_system_settings')
            ->where('setting_key', $key)
            ->exists();
    }

    /** @since 1.0.0 */
    public static function forget(string $key): void
    {
        DB::table('fundkit_system_settings')
            ->where('setting_key', $key)
            ->delete();
    }
}

SystemSetting::schema(function (Table $t): void {
    $t->string('setting_key', 64)->primary();
    $t->longText('setting_value');
    $t->datetime('updated_at');
});
