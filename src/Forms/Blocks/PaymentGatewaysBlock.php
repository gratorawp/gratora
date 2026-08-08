<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;
use Dono\Gateways\GatewayManager;

/**
 * Donor-facing gateway selector. The Preact runtime renders the interactive
 * version; this is the no-JS fallback. The block's `allowed` attribute is the
 * single source of which gateways a form offers (mirrored into
 * form.settings.gateways.allowed on save by FormService).
 *
 * @version 1.0.0
 */
final class PaymentGatewaysBlock implements Block
{
    public function __construct(private GatewayManager $gateways)
    {
    }

    /**
     * Block type name.
     */
    public function name(): string
    {
        return 'dono/payment-gateways';
    }

    /**
     * Block attribute schema.
     */
    public function attributes(): array
    {
        return [
            'allowed'      => ['type' => 'array',  'default' => []],
            'descriptions' => ['type' => 'object', 'default' => null],
            'style'        => ['type' => 'string', 'default' => 'cards'],
            // Empty means the first one the donor can actually use. Naming one
            // is a preference, not a guarantee: it is skipped when it cannot
            // serve the chosen currency or frequency.
            'preselected'  => ['type' => 'string', 'default' => ''],
        ];
    }

    /**
     * Renders the gateway selector; returns empty string when only one gateway is active.
     */
    public function render(array $attrs, string $content): string
    {
        $allowed = is_array($attrs['allowed'] ?? null)
            ? array_values(array_filter(array_map('strval', $attrs['allowed']), static fn ($s) => $s !== ''))
            : [];

        $options = $this->gateways->optionsMetaFor($allowed);

        // Hide-on-single mirrors the runtime: one option is auto-selected on
        // the form, no selector shown.
        if (count($options) <= 1) {
            return '';
        }

        $overrides = is_array($attrs['descriptions'] ?? null) ? $attrs['descriptions'] : [];
        foreach ($options as &$o) {
            $id = (string) ($o['id'] ?? '');
            if (isset($overrides[$id]) && is_string($overrides[$id]) && $overrides[$id] !== '') {
                $o['description'] = (string) $overrides[$id];
            }
        }
        unset($o);

        return View::loadRelative(__DIR__, 'views/payment-gateways', [
            'options' => $options,
        ]);
    }
}
