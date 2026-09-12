<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Core\CoreModule;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Upgrade\SchemaGuard;
use Gratora\Foundation\Upgrade\UpgradeRunner;

/**
 * Activation hooks pass $network_wide, not $fresh. Test the hook path so that flag cannot
 * override schema-based freshness detection.
 */
final class ActivationHookSignatureTest extends IntegrationTestCase
{

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
