<?php

declare(strict_types=1);

namespace Gratora\Foundation\Config;

defined('ABSPATH') || exit;

use Gratora\Vendor\Queryable\DB;
use Gratora\Vendor\Queryable\Model;
use Gratora\Vendor\Queryable\Schema\Table;

/** @since 1.0.0 */
final class SystemSetting extends Model
{
    protected string $table = 'gratora_system_settings';
    protected string $version = '1.0.0';
    protected string $primaryKey = 'setting_key';

    public string $setting_key;
    public string $setting_value = '';
    public string $updated_at;

    /** @since 1.0.0 */
    public static function read(string $key): ?string
    {
        $row = DB::table('gratora_system_settings')
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
        DB::table('gratora_system_settings')->upsert(
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
        return DB::table('gratora_system_settings')
            ->where('setting_key', $key)
            ->exists();
    }

    /** @since 1.0.0 */
    public static function forget(string $key): void
    {
        DB::table('gratora_system_settings')
            ->where('setting_key', $key)
            ->delete();
    }
}

SystemSetting::schema(function (Table $t): void {
    $t->string('setting_key', 64)->primary();
    $t->longText('setting_value');
    $t->datetime('updated_at');
});
