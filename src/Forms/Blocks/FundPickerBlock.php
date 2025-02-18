<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;
use Dono\Funds\FundRepository;

/**
 * Fund picker block.
 *
 * @version 1.0.0
 */
final class FundPickerBlock implements Block
{
    /** Campaign default fund for the current SSR render, set by the shortcode. */
    public static int $renderCampaignDefaultFundId = 0;

    /** Block name. */
    public function name(): string
    {
        return 'dono/fund-picker';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'            => ['type' => 'string',  'default' => ''],
            'defaultId'        => ['type' => 'string',  'default' => ''],
            'allowEmpty'       => ['type' => 'boolean', 'default' => false],
            'emptyLabel'       => ['type' => 'string',  'default' => ''],
            'emptyDescription' => ['type' => 'string',  'default' => ''],
            // Optional allowlist. Empty array (the default) shows every active fund.
            'fundIds'          => ['type' => 'array',   'default' => []],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $funds      = new FundRepository();
        $allowedIds = array_values(array_filter(array_map('intval', (array) ($attrs['fundIds'] ?? []))));
        $options    = $funds->pickerOptions($allowedIds !== [] ? $allowedIds : null);

        $selectable = array_values(array_map(
            static fn (array $o): string => $o['id'],
            array_filter($options, static fn (array $o): bool => $o['selectable'])
        ));

        $allowEmpty = (bool) ($attrs['allowEmpty'] ?? false);

        // '__none__' preselects the no-specific-fund tile; '' resolves to
        // org default then first selectable (no form context at block render).
        $requested = (string) ($attrs['defaultId'] ?? '');
        if ($requested === '__none__' && $allowEmpty) {
            $defaultId = '';
        } else {
            $defaultId = $requested;
            if (! in_array($defaultId, $selectable, true)) {
                $defaultId = '';
                $campFund = self::$renderCampaignDefaultFundId;
                if ($campFund > 0 && in_array((string) $campFund, $selectable, true)) {
                    $defaultId = (string) $campFund;
                }
                if ($defaultId === '') {
                    $org = $funds->default();
                    if ($org && in_array((string) (int) $org->id, $selectable, true)) {
                        $defaultId = (string) (int) $org->id;
                    }
                }
                if ($defaultId === '' && ! $allowEmpty && $selectable !== []) {
                    $defaultId = (string) $selectable[0];
                }
            }
        }

        return View::loadRelative(__DIR__, 'views/fund-picker', [
            'label'            => (string) ($attrs['label'] ?? ''),
            'options'          => $options,
            'defaultId'        => $defaultId,
            'allowEmpty'       => $allowEmpty,
            'emptyLabel'       => trim((string) ($attrs['emptyLabel'] ?? '')),
            'emptyDescription' => trim((string) ($attrs['emptyDescription'] ?? '')),
        ]);
    }
}
