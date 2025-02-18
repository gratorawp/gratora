<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Recurring frequency selector block.
 *
 * @version 1.0.0
 */
final class RecurringToggleBlock implements Block
{
    public const ALLOWED_FREQUENCIES = ['one-time', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];

    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/recurring-toggle';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'label'            => ['type' => 'string', 'default' => ''],
            'helpText'         => ['type' => 'string', 'default' => ''],
            'style'            => ['type' => 'string', 'default' => 'pills'],
            'defaultFrequency' => ['type' => 'string', 'default' => 'one-time'],
            'frequencies'      => ['type' => 'array',  'default' => ['one-time', 'monthly']],
        ];
    }

    /**
     * Renders the frequency selector.
     */
    public function render(array $attrs, string $content): string
    {
        $style = (string) ($attrs['style'] ?? 'pills');
        if (! in_array($style, ['pills', 'tabs'], true)) {
            $style = 'pills';
        }

        $default = (string) ($attrs['defaultFrequency'] ?? 'one-time');
        if (! in_array($default, self::ALLOWED_FREQUENCIES, true)) {
            $default = 'one-time';
        }

        return View::loadRelative(__DIR__, 'views/recurring-toggle', [
            'label'            => (string) ($attrs['label']    ?? ''),
            'helpText'         => (string) ($attrs['helpText'] ?? ''),
            'style'            => $style,
            'defaultFrequency' => $default,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function normalizeFrequencies($raw): array
    {
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $f) {
            $f = (string) $f;
            if (in_array($f, self::ALLOWED_FREQUENCIES, true) && ! in_array($f, $out, true)) {
                $out[] = $f;
            }
        }
        return $out;
    }
}
