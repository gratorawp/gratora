<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Date input field block.
 *
 * @version 1.0.0
 */
final class DateBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/date';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'    => ['type' => 'string',  'default' => ''],
            'helpText' => ['type' => 'string',  'default' => ''],
            'required' => ['type' => 'boolean', 'default' => false],
            'minDate'  => ['type' => 'string',  'default' => ''],
            'maxDate'  => ['type' => 'string',  'default' => ''],
            'field'    => ['type' => 'string',  'default' => ''],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/date', [
            'label'    => (string) ($attrs['label']    ?? ''),
            'helpText' => (string) ($attrs['helpText'] ?? ''),
            'required' => (bool)   ($attrs['required'] ?? false),
            'minDate'  => self::normalizeDate((string) ($attrs['minDate'] ?? '')),
            'maxDate'  => self::normalizeDate((string) ($attrs['maxDate'] ?? '')),
            'field'    => self::slugifyField((string) ($attrs['field'] ?? '')),
        ]);
    }

    /** Validate an ISO date string, returning '' when malformed. */
    public static function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }

    /** Slugify a field name to snake_case. */
    public static function slugifyField(string $raw): string
    {
        $s = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($raw)));
        return trim($s, '_');
    }
}
