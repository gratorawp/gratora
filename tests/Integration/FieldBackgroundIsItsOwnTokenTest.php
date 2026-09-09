<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Styling\Tokens;

/**
 * The donation form painted its fields, its amount box and its currency picker
 * with the same token as the surface behind them, so an org that set a brand
 * background got that colour inside every box: a block of colour with no
 * visible inputs in it. The two are separate settings now.
 */
final class FieldBackgroundIsItsOwnTokenTest extends IntegrationTestCase
{
    public function test_the_field_fill_is_offered_apart_from_the_surface(): void
    {
        $catalogue = Tokens::catalogue();

        $this->assertArrayHasKey('gratora-field-bg', $catalogue);
        $this->assertSame('surface', $catalogue['gratora-field-bg']['group']);
    }

    /** A brand that only sets the surface leaves the boxes readable. */
    public function test_setting_the_surface_does_not_move_the_field_fill(): void
    {
        $defaults = Tokens::defaults();

        $this->assertSame('#ffffff', $defaults['gratora-field-bg']);

        $saved = Tokens::sanitize(['gratora-bg' => '#ed1212']);

        $this->assertArrayNotHasKey('gratora-field-bg', $saved);
    }

    public function test_a_field_fill_the_org_chose_is_kept(): void
    {
        $this->assertSame(
            ['gratora-field-bg' => '#101828'],
            Tokens::sanitize(['gratora-field-bg' => '#101828'])
        );
    }
}
