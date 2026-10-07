<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\Addons\AddonsCatalog;
use Gratora\Admin\Pages\AddonsPage;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;

/**
 * The Add-ons link is drawn in amber, and each admin colour scheme paints the
 * menu a ground of its own. Before WordPress 7.0, blue, sunrise, ocean and
 * coffee paint one the amber reads at 1.4 to 4.4 to 1 on (measured on 6.9),
 * so there the link keeps the ink WordPress gives it.
 */
final class TheAddOnsLinkIsColouredOnlyWhereItReadsTest extends IntegrationTestCase
{
    private function stylesheet(): string
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $c = Plugin::instance()->container;
        (new AddonsPage(new AddonsCatalog($c->get(Clock::class)), $c->get(LicenseService::class)))->menuLinkStyle();

        return implode("\n", (array) wp_styles()->get_data('gratora-addons-menu-link', 'after'));
    }

    public function test_the_schemes_with_a_dark_menu_take_the_amber(): void
    {
        $css = $this->stylesheet();

        foreach (['modern', 'fresh', 'midnight', 'ectoplasm'] as $scheme) {
            $this->assertStringContainsString('.admin-color-' . $scheme, $css);
        }
        $this->assertStringContainsString('#e89940', $css);
    }

    public function test_the_light_scheme_takes_the_darker_ink(): void
    {
        $this->assertMatchesRegularExpression('/\.admin-color-light [^{]+\{ color: #b45309; \}/', $this->stylesheet());
    }

    public function test_a_scheme_the_amber_cannot_be_read_on_is_left_as_wordpress_draws_it(): void
    {
        $css   = $this->stylesheet();
        $reads = is_wp_version_compatible('7.0');

        foreach (['blue', 'sunrise', 'ocean', 'coffee'] as $scheme) {
            $this->assertSame($reads, str_contains($css, '.admin-color-' . $scheme), $scheme);
        }
    }
}
