<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Core\CoreModule;
use FundKit\Foundation\Plugin;
use FundKit\Foundation\Upgrade\SchemaGuard;
use FundKit\Foundation\Upgrade\UpgradeRunner;

/**
 * WordPress calls an activation hook with $network_wide, and the hook pointed
 * straight at onActivation(?bool $fresh), so that flag landed in $fresh.
 *
 * Because it is always a bool, the `$fresh ??= <read the schema stamp>`
 * auto-detect in activate() never ran, and the answer was wrong both ways:
 *
 *   single-site activation -> $fresh = false -> a genuinely fresh install never
 *       stamped its upgrade routines done, leaving backfills to run later
 *       against tables no earlier release ever wrote;
 *   network activation     -> $fresh = true  -> an install that was NOT fresh
 *       stamped migrations it still needed as already applied.
 *
 * No suite could see it: all ten bootstraps call onActivation() with no
 * argument, which is the auto-detect path the hook never reaches.
 */
final class ActivationHookSignatureTest extends IntegrationTestCase
{
    public function test_the_hook_registered_with_wordpress_is_not_the_fresh_flag_one(): void
    {
        $plugin = dirname(__DIR__, 2) . '/fundkit/fundkit.php';
        if (! is_file($plugin)) {
            $plugin = dirname(__DIR__, 2) . '/fundkit.php';
        }
        $source = (string) file_get_contents($plugin);

        $this->assertStringContainsString(
            "register_activation_hook(__FILE__, [ Plugin::class, 'onPluginActivated'])",
            $source,
            'the hook must land on the entry point that ignores $network_wide'
        );
        $this->assertStringNotContainsString(
            "register_activation_hook(__FILE__, [ Plugin::class, 'onActivation'])",
            $source,
            'pointing WordPress at onActivation() feeds $network_wide into $fresh'
        );
    }

    /**
     * The behaviour, not just the wiring. This site's schema is already
     * stamped, so it is NOT a fresh install; a network activation must not
     * conclude otherwise and stamp pending migrations as done.
     */
    public function test_a_network_activation_does_not_declare_an_existing_install_fresh(): void
    {
        $this->assertNotNull(
            get_option(SchemaGuard::OPTION, null),
            'precondition: this install is already stamped, so it is not fresh'
        );

        $routines = CoreModule::upgradeRoutines();
        $this->assertNotSame([], $routines, 'precondition: there are routines to stamp');

        delete_option(UpgradeRunner::OPTION_DONE);

        // WordPress passes true here when the plugin is network-activated.
        Plugin::onPluginActivated(true);

        $this->assertSame(
            [],
            UpgradeRunner::completed(),
            'an install with a stamped schema is not fresh, whatever WordPress says about the network'
        );
    }
}
