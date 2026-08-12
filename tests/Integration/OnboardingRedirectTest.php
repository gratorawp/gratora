<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Onboarding\Onboarding;
use Dono\Onboarding\OnboardingPage;

/**
 * The wizard greets an admin once and then gets out of the way.
 *
 * Two of these are about other people's code. admin-post.php runs admin_init
 * like any other admin request, so a redirect there answers a POST that belongs
 * to whichever plugin registered the handler, and the payload is gone. And the
 * screen allow-list this replaces was measured against get_current_screen(),
 * which core has not populated yet at admin_init: it read null on every request,
 * so nothing was ever allowed through and the redirect was unconditional.
 */
final class OnboardingRedirectTest extends IntegrationTestCase
{
    private Onboarding $onboarding;

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->onboarding = new Onboarding();

        update_option(Onboarding::OPTION, 'pending', false);
        set_transient('dono_onboarding_greet', 1, 300);

        $GLOBALS['pagenow'] = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_GET['page']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pagenow'], $_GET['page']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        delete_transient('dono_onboarding_greet');

        parent::tearDown();
    }

    public function test_a_fresh_install_greets_the_admin(): void
    {
        $this->assertTrue($this->onboarding->shouldRedirect());
    }

    public function test_it_greets_once_and_not_again(): void
    {
        $this->assertTrue($this->onboarding->shouldRedirect());

        // What maybeRedirect() does before sending. The send itself is followed
        // by exit, so the greeting being spent is the observable half.
        delete_transient('dono_onboarding_greet');

        $this->assertFalse(
            $this->onboarding->shouldRedirect(),
            'the wizard is still pending, and that is exactly when it must stop redirecting'
        );
    }

    public function test_a_post_is_never_answered_with_a_redirect(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // admin-post.php is the case that matters, but the rule is the method:
        // a body belongs to whatever was going to read it.
        $this->assertFalse($this->onboarding->shouldRedirect());
    }

    public function test_admin_post_is_left_alone_even_on_a_get(): void
    {
        $GLOBALS['pagenow'] = 'admin-post.php';

        $this->assertFalse($this->onboarding->shouldRedirect());
    }

    public function test_the_plugins_screen_stays_reachable(): void
    {
        foreach (['plugins.php', 'plugin-install.php', 'update.php', 'update-core.php'] as $screen) {
            $GLOBALS['pagenow'] = $screen;
            $this->assertFalse(
                $this->onboarding->shouldRedirect(),
                $screen . ' must stay reachable, or an admin cannot deactivate the plugin'
            );
        }
    }

    public function test_the_wizard_does_not_redirect_to_itself(): void
    {
        $_GET['page'] = OnboardingPage::PAGE_ID;

        $this->assertFalse($this->onboarding->shouldRedirect());
    }

    public function test_a_finished_install_is_left_alone(): void
    {
        update_option(Onboarding::OPTION, 'completed', false);

        $this->assertFalse($this->onboarding->shouldRedirect());
    }

    public function test_a_dismissed_wizard_is_left_alone(): void
    {
        update_option(Onboarding::OPTION, 'dismissed', false);

        $this->assertFalse($this->onboarding->shouldRedirect());
    }

    public function test_someone_who_cannot_configure_the_site_is_left_alone(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse($this->onboarding->shouldRedirect());
    }
}
