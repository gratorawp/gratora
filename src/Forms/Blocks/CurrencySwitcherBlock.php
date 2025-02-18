<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;
use Dono\Settings\SettingsService;

/**
 * Currency switcher block.
 *
 * @version 1.0.0
 */
final class CurrencySwitcherBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/currency-switcher';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        // Empty currencies = offer the org base only.
        return [
            'currencies' => ['type' => 'array',  'default' => []],
            'label'      => ['type' => 'string', 'default' => ''],
            'style'      => ['type' => 'string', 'default' => 'dropdown'],
            'align'      => ['type' => 'string', 'default' => 'left'],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $s = self::settings($attrs);
        return View::loadRelative(__DIR__, 'views/currency-switcher', [
            'currencies' => self::resolve($attrs['currencies'] ?? []),
            'label'      => $s['label'],
            'style'      => $s['style'],
            'align'      => $s['align'],
        ]);
    }

    /**
     * Presentation settings with enum coercion and defaults.
     *
     * @param array<string,mixed> $attrs
     * @return array{label:string,style:string,align:string}
     */
    public static function settings(array $attrs): array
    {
        $style = (string) ($attrs['style'] ?? 'dropdown');
        $align = (string) ($attrs['align'] ?? 'left');
        return [
            'label' => trim((string) ($attrs['label'] ?? '')),
            'style' => in_array($style, ['dropdown', 'pills'], true) ? $style : 'dropdown',
            'align' => in_array($align, ['left', 'right'], true) ? $align : 'left',
        ];
    }

    /**
     * Chosen codes intersected with org-enabled currencies, base first.
     * Never offers a currency the org has not enabled.
     *
     * @return list<string>
     */
    public static function resolve(mixed $rawCurrencies): array
    {
        $chosen = self::parse($rawCurrencies);

        $cur  = (new SettingsService())->get('currency-locale');
        $base = strtoupper((string) ($cur['default_currency'] ?? 'USD'));
        $supported = is_array($cur['supported_currencies'] ?? null)
            ? array_map(static fn ($c): string => strtoupper((string) $c), $cur['supported_currencies'])
            : [];
        $allowed = array_merge([$base], $supported);

        $out = [$base];
        foreach ($chosen as $c) {
            if ($c !== $base && in_array($c, $allowed, true) && ! in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Parse raw codes with a non-empty fallback.
     *
     * @return list<string>
     */
    public static function normalize(mixed $raw): array
    {
        return self::parse($raw) ?: ['USD'];
    }

    /** @return list<string> */
    private static function parse(mixed $raw): array
    {
        $codes = is_array($raw) ? $raw : [];
        $out   = [];
        foreach ($codes as $code) {
            $c = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{3}$/', $c) && ! in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
