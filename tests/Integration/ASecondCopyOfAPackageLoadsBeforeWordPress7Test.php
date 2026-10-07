<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\RepeatedPackageRegistration;

/**
 * Core and its add-ons each carry their own copy of WordPress's data views
 * package. WordPress before 7.0 throws at the second one on a screen, so on
 * those versions a script that answers it again is printed after WordPress's
 * own. From 7.0 WordPress answers by itself and nothing is printed.
 */
final class ASecondCopyOfAPackageLoadsBeforeWordPress7Test extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_scripts()->add_data('wp-private-apis', 'after', []);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['plugin_page']);
        wp_scripts()->add_data('wp-private-apis', 'after', []);

        parent::tearDown();
    }

    private function printedAfterWordPressOn(string $page): string
    {
        $GLOBALS['plugin_page'] = $page;
        (new RepeatedPackageRegistration())->allow();

        return implode("\n", (array) wp_scripts()->get_data('wp-private-apis', 'after'));
    }

    /** @return array<string, array{0:string}> */
    public function screensOfOurs(): array
    {
        return [
            'the dashboard'       => ['gratora'],
            'campaigns'           => ['gratora-campaigns'],
            "an add-on's screen"  => ['gratora-events'],
        ];
    }

    /** @dataProvider screensOfOurs */
    public function test_it_is_printed_on_our_screens_only_where_wordpress_would_throw(string $page): void
    {
        $this->assertSame(
            ! is_wp_version_compatible('7.0'),
            str_contains($this->printedAfterWordPressOn($page), '__dangerousOptInToUnstableAPIsOnlyForCoreModules')
        );
    }

    public function test_a_screen_that_is_not_ours_is_left_alone(): void
    {
        $this->assertSame('', $this->printedAfterWordPressOn('somebody-elses-plugin'));
    }
}
