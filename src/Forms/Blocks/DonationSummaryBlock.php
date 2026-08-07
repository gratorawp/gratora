<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * What the donor is about to give, read back to them before they commit.
 *
 * The numbers come from the donor's own choices, so only the Preact runtime can
 * fill them in; this renders the empty shell for the no-JS pass, the same way
 * the other value-bearing blocks do.
 *
 * It is a block rather than part of the submit step because where a recap
 * belongs is a layout decision. Above the button on a short form, at the top of
 * a final wizard page on a long one, and nowhere at all on a form whose single
 * field is the amount.
 *
 * @version 1.0.0
 */
final class DonationSummaryBlock implements Block
{
    public function name(): string
    {
        return 'dono/donation-summary';
    }

    public function attributes(): array
    {
        return [
            'showDonor'    => ['type' => 'boolean', 'default' => true],
            'showGateway'  => ['type' => 'boolean', 'default' => true],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/donation-summary', [
            'showDonor'   => (bool) ($attrs['showDonor']   ?? true),
            'showGateway' => (bool) ($attrs['showGateway'] ?? true),
        ]);
    }
}
