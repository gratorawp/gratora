<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\AntiSpamGuard;
use Gratora\Foundation\Plugin;
use WP_Error;

final class TestModeQuotaReliefTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option('gratora_gateway_config');
        parent::tearDown();
    }

    private function guard(): AntiSpamGuard
    {
        // Rebuilt per test: TestMode is injected, and it reads the switch.
        return new AntiSpamGuard(
            Plugin::instance()->container->get(\Gratora\Foundation\Identity\IdentityHasher::class),
            Plugin::instance()->container->get(\Gratora\Gateways\TestMode::class)
        );
    }

    private function testMode(bool $on): void
    {
        update_option('gratora_gateway_config', ['test_mode' => $on]);
    }

    /** Spend the per-IP budget until refused, and answer how many got through. */
    private function drain(AntiSpamGuard $guard, string $namespace, int $max): int
    {
        $allowed = 0;
        for ($i = 0; $i < ($max * 20) + 5; $i++) {
            if ($guard->consumeIpBudget($namespace, $max, 900) instanceof WP_Error) {
                return $allowed;
            }
            $allowed++;
        }

        return $allowed;
    }

    public function test_production_caps_hold_exactly(): void
    {
        $this->testMode(false);

        $this->assertSame(5, $this->drain($this->guard(), 'gratora_relief_' . uniqid(), 5));
    }

    public function test_test_mode_raises_the_cap_without_removing_it(): void
    {
        $this->testMode(true);

        $allowed = $this->drain($this->guard(), 'gratora_relief_' . uniqid(), 5);

        $this->assertGreaterThan(5, $allowed, 'automation must not be held to the production cap');
        $this->assertSame(50, $allowed, 'but there is still a ceiling');
    }

    /**
     * A new install starts in test mode with a page anyone can reach, and may
     * sit there for weeks. The relief is for whoever is rehearsing on it, not
     * for a stranger who found the form.
     */
    public function test_on_a_live_site_a_visitor_is_held_to_the_production_cap_in_test_mode(): void
    {
        $this->testMode(true);
        $this->asAVisitorToALiveSite();

        $this->assertSame(5, $this->drain($this->guard(), 'gratora_relief_' . uniqid(), 5));
    }

    public function test_nor_can_a_visitor_there_mail_one_address_more_than_on_any_live_site(): void
    {
        $this->testMode(true);
        $this->asAVisitorToALiveSite();
        $guard = $this->guard();
        $email = 'relief-' . uniqid() . '@example.test';

        $allowed = 0;
        for ($i = 0; $i < 100; $i++) {
            if ($guard->consumeEmailQuota($email) instanceof WP_Error) {
                break;
            }
            $allowed++;
        }

        $this->assertSame(3, $allowed);
    }

    public function test_whoever_manages_the_plugin_keeps_the_relief_on_a_live_site(): void
    {
        $this->testMode(true);
        add_filter('gratora.spam.test_mode_relief_for_visitors', '__return_false');

        $this->assertSame(50, $this->drain($this->guard(), 'gratora_relief_' . uniqid(), 5));
    }

    public function test_a_test_rig_keeps_it_for_visitors(): void
    {
        $this->testMode(true);
        wp_set_current_user(0);
        add_filter('gratora.spam.test_mode_relief_for_visitors', '__return_true');

        $this->assertSame(50, $this->drain($this->guard(), 'gratora_relief_' . uniqid(), 5));
    }

    /** What the filter is given when nobody has spoken: the site's own word on what it is. */
    public function test_a_site_that_calls_itself_production_gives_visitors_none_unasked(): void
    {
        if (wp_get_environment_type() !== 'production') {
            $this->markTestSkipped('This run does not report itself as production.');
        }
        $this->testMode(true);
        wp_set_current_user(0);

        $this->assertSame(5, $this->drain($this->guard(), 'gratora_relief_' . uniqid(), 5));
    }

    private function asAVisitorToALiveSite(): void
    {
        wp_set_current_user(0);
        add_filter('gratora.spam.test_mode_relief_for_visitors', '__return_false');
    }

    public function test_the_email_quota_also_keeps_a_ceiling_in_test_mode(): void
    {
        $this->testMode(true);
        $guard = $this->guard();
        $email = 'relief-' . uniqid() . '@example.test';

        $allowed = 0;
        for ($i = 0; $i < 100; $i++) {
            if ($guard->consumeEmailQuota($email) instanceof WP_Error) {
                break;
            }
            $allowed++;
        }

        $this->assertGreaterThan(3, $allowed);
        $this->assertLessThan(100, $allowed, 'an unmetered receipt-mail path is what this prevents');
    }
}
