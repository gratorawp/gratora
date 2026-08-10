<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;

/**
 * Donation amount block with preset tiers and optional custom input.
 *
 * @since 1.0.0
 */
final class DonationAmountBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/donation-amount';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'presets'     => ['type' => 'array',   'default' => [
                ['cents' => 1000,  'impact' => '', 'preselected' => false],
                ['cents' => 2500,  'impact' => '', 'preselected' => false],
                ['cents' => 5000,  'impact' => '', 'preselected' => false],
                ['cents' => 10000, 'impact' => '', 'preselected' => false],
            ]],
            'allowCustom'  => ['type' => 'boolean', 'default' => true],
            // 0 means "no form-level minimum", and the org-wide spam floor
            // still applies underneath.
            'minCents'     => ['type' => 'number',  'default' => 0],
            'currency'     => ['type' => 'string',  'default' => ''],
            'donationType' => ['type' => 'string',  'default' => 'multi'],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $fixed   = (string) ($attrs['donationType'] ?? 'multi') === 'fixed';
        $presets = $fixed ? [] : self::normalizePresets($attrs['presets'] ?? null);

        $default = 0;
        if (! $fixed) {
            $default = (int) ($presets[0]['cents'] ?? 1000);
            foreach ($presets as $p) {
                if (! empty($p['preselected'])) { $default = (int) $p['cents']; break; }
            }
        }

        return View::loadRelative(__DIR__, 'views/donation-amount', [
            'presets'     => $presets,
            'allowCustom' => $fixed ? true : (bool) ($attrs['allowCustom'] ?? true),
            'currency'    => strtoupper(trim((string) ($attrs['currency'] ?? ''))) ?: Money::defaultCurrency(),
            'default'     => $default,
        ]);
    }

    /**
     * @return list<array{cents:int,impact:string,preselected:bool}>
     *
     * @since 1.0.0
     */
    public static function normalizePresets(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        $out   = [];
        foreach ($items as $item) {
            if (is_numeric($item)) {
                $cents = (int) $item;
                if ($cents > 0) $out[] = ['cents' => $cents, 'impact' => '', 'preselected' => false];
                continue;
            }
            if (is_array($item)) {
                $cents   = (int) ($item['cents'] ?? 0);
                $impact  = (string) ($item['impact'] ?? '');
                $preselected = (bool) ($item['preselected'] ?? false);
                if ($cents > 0) $out[] = ['cents' => $cents, 'impact' => $impact, 'preselected' => $preselected];
            }
        }
        if (empty($out)) {
            $out = [
                ['cents' => 1000,  'impact' => '', 'preselected' => false],
                ['cents' => 2500,  'impact' => '', 'preselected' => false],
                ['cents' => 5000,  'impact' => '', 'preselected' => false],
                ['cents' => 10000, 'impact' => '', 'preselected' => false],
            ];
        }
        return $out;
    }
}
