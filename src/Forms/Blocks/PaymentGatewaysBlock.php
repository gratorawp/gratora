<?php

declare(strict_types=1);

namespace Gratora\Forms\Blocks;

use Gratora\Foundation\Helpers\View;
use Gratora\Gateways\GatewayManager;

/**
 * Donor-facing gateway selector. The Preact runtime renders the interactive
 * version; this is the no-JS fallback. The block's `allowed` attribute is the
 * single source of which gateways a form offers (mirrored into
 * form.settings.gateways.allowed on save by FormService).
 *
 * @since 1.0.0
 */
final class PaymentGatewaysBlock implements Block
{
    /** @since 1.0.0 */
    public function __construct(private GatewayManager $gateways)
    {
    }

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'gratora/payment-gateways';
    }

    /** @since 1.0.0 */
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
     * The mode the form being rendered runs in.
     *
     * Set by DonationFormShortcode around do_blocks, the way the fund picker
     * takes its campaign default: the block is rendered inside that call and
     * has no form of its own to resolve. Left null, the site's mode answers,
     * which is right for a preview with no form in the request.
     *
     * @since 1.0.0
     */
    public static ?bool $renderTestMode = null;

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        $allowed = is_array($attrs['allowed'] ?? null)
            ? array_values(array_filter(array_map('strval', $attrs['allowed']), static fn ($s) => $s !== ''))
            : [];

        $options = $this->gateways->optionsMetaFor($allowed, self::$renderTestMode);

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
