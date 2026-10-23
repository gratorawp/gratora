<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Core\Activator;
use Gratora\Foundation\Plugin;
use Gratora\Onboarding\Onboarding;
use Gratora\Onboarding\OnboardingPage;

/**
 * Right after Activate the Plugins screen says where Gratora went, once, and
 * the first other admin screen opens the setup, however long that takes.
 */
final class AfterActivatingTheWayIsShownTest extends IntegrationTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);

        $GLOBALS['pagenow'] = 'plugins.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($GLOBALS['plugin_page']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pagenow'], $GLOBALS['plugin_page']);
        delete_option(Onboarding::GREET);
        delete_option(Onboarding::NOTICE);

        parent::tearDown();
    }

    private function switchOnForTheFirstTime(bool $networkWide = false): void
    {
        delete_option(Activator::OPT_ACTIVATED_AT);
        delete_option(Onboarding::OPTION);

        Plugin::onPluginActivated($networkWide);
    }

    private function pluginsScreen(): string
    {
        $GLOBALS['pagenow'] = 'plugins.php';
        ob_start();
        (new Onboarding())->activationNotice();

        return (string) ob_get_clean();
    }

    private function anotherAdminScreen(): bool
    {
        $GLOBALS['pagenow'] = 'index.php';

        return (new Onboarding())->shouldRedirect();
    }

    private function anHourPasses(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name LIKE %s",
            time() - 1,
            $wpdb->esc_like('_transient_timeout_gratora_') . '%'
        ));
        wp_cache_flush();
    }

    /** @return array<string,string> */
    private function rowLinks(): array
    {
        return (new Onboarding())->setUpLink(['deactivate' => '<a href="#">Deactivate</a>']);
    }

    public function test_the_plugins_screen_says_where_to_go_once(): void
    {
        $this->switchOnForTheFirstTime();

        $first = $this->pluginsScreen();
        $this->assertStringContainsString('Set up Gratora', $first);
        $this->assertStringContainsString('is-dismissible', $first);
        $this->assertStringContainsString('page=' . OnboardingPage::PAGE_ID, $first);

        $this->assertSame('', trim($this->pluginsScreen()), 'shown again on the next visit');
    }

    public function test_someone_who_cannot_set_gratora_up_is_not_shown_it(): void
    {
        $this->switchOnForTheFirstTime();

        $plugins = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $plugins)->add_cap('activate_plugins');
        wp_set_current_user($plugins);
        $this->assertSame('', trim($this->pluginsScreen()));

        wp_set_current_user($this->admin);
        $this->assertStringContainsString('Set up Gratora', $this->pluginsScreen(), 'spent on someone who never saw it');
    }

    public function test_nothing_is_shown_after_a_network_wide_activation(): void
    {
        $this->switchOnForTheFirstTime(networkWide: true);

        $this->assertSame('', trim($this->pluginsScreen()));
        $this->assertFalse($this->anotherAdminScreen());
    }

    public function test_switching_it_back_on_shows_nothing(): void
    {
        Plugin::onPluginActivated();

        $this->assertSame('', trim($this->pluginsScreen()));
        $this->assertFalse($this->anotherAdminScreen());
    }

    public function test_the_setup_still_opens_on_the_first_other_screen_an_hour_later(): void
    {
        $this->switchOnForTheFirstTime();
        $this->anHourPasses();

        $this->assertTrue($this->anotherAdminScreen());
    }

    public function test_opening_the_setup_from_the_notice_spends_the_greeting_and_the_notice(): void
    {
        $this->switchOnForTheFirstTime();

        $GLOBALS['pagenow']     = 'admin.php';
        $GLOBALS['plugin_page'] = OnboardingPage::PAGE_ID;
        (new Onboarding())->maybeRedirect();
        unset($GLOBALS['plugin_page']);

        $this->assertFalse($this->anotherAdminScreen(), 'sent back to the setup after leaving it');
        $this->assertSame('', trim($this->pluginsScreen()));
    }

    public function test_the_row_link_opens_the_setup_and_then_the_setup_check(): void
    {
        $this->switchOnForTheFirstTime();

        $links = $this->rowLinks();
        $this->assertSame(['set-up', 'deactivate'], array_keys($links));
        $this->assertStringContainsString('page=' . OnboardingPage::PAGE_ID, $links['set-up']);

        update_option(Onboarding::OPTION, 'completed', false);
        $this->assertStringContainsString('page=gratora-settings#setup', $this->rowLinks()['set-up']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertArrayNotHasKey('set-up', $this->rowLinks());
    }
}
