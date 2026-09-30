<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Admin\Pages\AddonsPage;

/**
 * The add-ons screen says which add-ons this site has from the plugins
 * WordPress knows about, and offers activation only where WordPress would
 * accept it.
 */
final class AnAddOnsPageKnowsWhatTheSiteHasTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_cache_delete('plugins', 'plugins');
        parent::tearDown();
    }

    /** @param list<string> $files */
    private function installed(array $files): void
    {
        $plugins = [];
        foreach ($files as $file) {
            $plugins[$file] = ['Name' => basename($file, '.php'), 'Version' => '1.0.0'];
        }

        wp_cache_set('plugins', ['' => $plugins], 'plugins');
    }

    /** @return array<string, array<string, mixed>> */
    private function addons(): array
    {
        return array_column((new AddonsPage())->addons(), null, 'slug');
    }

    public function test_an_add_on_the_site_does_not_have_is_only_described(): void
    {
        $this->installed([]);

        $events = $this->addons()['events'];

        $this->assertSame('available', $events['status']);
        $this->assertSame('', $events['activateUrl']);
        $this->assertSame('https://gratora.net/add-ons/events/', $events['url']);
    }

    public function test_an_installed_add_on_is_activated_through_wordpress(): void
    {
        $file = 'gratora-events/gratora-events.php';
        $this->installed([$file]);

        $events = $this->addons()['events'];
        wp_parse_str((string) wp_parse_url($events['activateUrl'], PHP_URL_QUERY), $query);

        $this->assertSame('installed', $events['status']);
        $this->assertStringStartsWith(self_admin_url('plugins.php') . '?', $events['activateUrl']);
        $this->assertSame('activate', $query['action'] ?? null);
        $this->assertSame($file, $query['plugin'] ?? null);
        $this->assertNotFalse(
            wp_verify_nonce((string) ($query['_wpnonce'] ?? ''), 'activate-plugin_' . $file),
            'WordPress would refuse the activation'
        );
    }

    public function test_an_active_add_on_says_so_and_offers_no_activation(): void
    {
        $file = 'gratora-tributes/gratora-tributes.php';
        $this->installed([$file]);
        update_option('active_plugins', [$file]);

        $tributes = $this->addons()['tributes'];

        $this->assertSame('active', $tributes['status']);
        $this->assertSame('', $tributes['activateUrl']);
    }

    public function test_an_add_on_unpacked_into_a_renamed_folder_is_still_found(): void
    {
        $this->installed(['gratora-p2p-main/gratora-p2p.php']);

        $this->assertSame('installed', $this->addons()['peer-to-peer-fundraising']['status']);
    }

    public function test_an_active_copy_counts_over_an_inactive_one(): void
    {
        $this->installed(['gratora-connect-old/gratora-connect.php', 'gratora-connect/gratora-connect.php']);
        update_option('active_plugins', ['gratora-connect/gratora-connect.php']);

        $this->assertSame('active', $this->addons()['connect']['status']);
    }

    public function test_someone_who_cannot_activate_plugins_is_not_offered_activation(): void
    {
        $this->installed(['gratora-gift-aid/gratora-gift-aid.php']);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $giftAid = $this->addons()['gift-aid'];

        $this->assertSame('installed', $giftAid['status']);
        $this->assertSame('', $giftAid['activateUrl']);
    }
}
