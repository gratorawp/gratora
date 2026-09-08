<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Plugin;

/**
 * An add-on extends core's classes, and core's autoloader goes with core. Left
 * active after core is switched off, the add-on fatals as soon as anything
 * touches one of those classes, including its own deactivation hook, so the
 * site owner cannot switch off the thing breaking their site from the screen
 * that would switch it off.
 */
final class DeactivatesDependentsTest extends IntegrationTestCase
{
    private string $fixtureDir = '';

    protected function tearDown(): void
    {
        if ($this->fixtureDir !== '' && is_dir($this->fixtureDir)) {
            array_map('unlink', (array) glob($this->fixtureDir . '/*'));
            rmdir($this->fixtureDir);
        }

        update_option('active_plugins', []);
        remove_all_filters('fundkit.dependent_plugins');

        parent::tearDown();
    }

    /** Writes a plugin whose header declares what it needs, and activates it. */
    private function givenActivePlugin(string $slug, string $requires = ''): string
    {
        $this->fixtureDir = WP_PLUGIN_DIR . '/' . $slug;
        if (! is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0777, true);
        }

        $header = "<?php\n/**\n * Plugin Name: {$slug}\n";
        if ($requires !== '') {
            $header .= " * Requires Plugins: {$requires}\n";
        }
        $header .= " */\n";

        file_put_contents($this->fixtureDir . '/' . $slug . '.php', $header);

        $basename = $slug . '/' . $slug . '.php';
        update_option('active_plugins', [plugin_basename(FUNDKIT_FILE), $basename]);

        return $basename;
    }

    private function deactivateCore(): void
    {
        $m = new \ReflectionMethod(Plugin::class, 'deactivateDependents');
        $m->setAccessible(true);
        $m->invoke(null);
    }

    private function activePlugins(): array
    {
        return (array) get_option('active_plugins', []);
    }

    public function test_an_addon_that_declares_core_is_switched_off_with_it(): void
    {
        $addon = $this->givenActivePlugin('fundkit-test-addon', 'fundraising-toolkit');

        $this->deactivateCore();

        $this->assertNotContains($addon, $this->activePlugins(), 'the add-on was left active without core');
    }

    public function test_an_unrelated_plugin_is_left_alone(): void
    {
        $other = $this->givenActivePlugin('fundkit-test-addon', 'some-other-plugin');

        $this->deactivateCore();

        $this->assertContains($other, $this->activePlugins());
    }

    public function test_a_plugin_declaring_nothing_is_left_alone(): void
    {
        $other = $this->givenActivePlugin('fundkit-test-addon');

        $this->deactivateCore();

        $this->assertContains($other, $this->activePlugins());
    }

    public function test_core_is_found_among_several_declared_dependencies(): void
    {
        $addon = $this->givenActivePlugin('fundkit-test-addon', 'woocommerce, fundraising-toolkit');

        $this->deactivateCore();

        $this->assertNotContains($addon, $this->activePlugins());
    }

    public function test_core_does_not_deactivate_itself(): void
    {
        $this->givenActivePlugin('fundkit-test-addon', 'fundraising-toolkit');

        $this->deactivateCore();

        // WordPress writes active_plugins after the hook returns, so core
        // removing itself here would corrupt what it then writes.
        $this->assertContains(plugin_basename(FUNDKIT_FILE), $this->activePlugins());
    }

    public function test_the_filter_can_name_a_plugin_the_header_missed(): void
    {
        $other = $this->givenActivePlugin('fundkit-test-addon');

        add_filter('fundkit.dependent_plugins', static function (array $list) use ($other): array {
            $list[] = $other;
            return $list;
        });

        $this->deactivateCore();

        $this->assertNotContains($other, $this->activePlugins());
    }
}
