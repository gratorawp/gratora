<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * The numbers come from the donor's own choices, so only the Preact runtime can
 * fill them in; this renders the empty shell for the no-JS pass.
 *
 * @since 1.0.0
 */
final class DonationSummaryBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/donation-summary';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'showDonor'    => ['type' => 'boolean', 'default' => true],
            'showGateway'  => ['type' => 'boolean', 'default' => true],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/donation-summary', [
            'showDonor'   => (bool) ($attrs['showDonor']   ?? true),
            'showGateway' => (bool) ($attrs['showGateway'] ?? true),
        ]);
    }
}
