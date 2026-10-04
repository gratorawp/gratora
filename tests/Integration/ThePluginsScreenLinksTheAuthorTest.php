<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

/**
 * For a plugin WordPress.org hosts, the Plugins screen shows "View details"
 * where "Visit plugin site" would be. The author is then the one link to the
 * plugin's site on that screen.
 */
final class ThePluginsScreenLinksTheAuthorTest extends IntegrationTestCase
{
    public function test_the_author_links_to_the_plugins_site(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin = get_plugin_data(GRATORA_FILE, true, false);

        $this->assertSame('<a href="https://gratora.net/">Gratora</a>', $plugin['Author']);
    }
}
