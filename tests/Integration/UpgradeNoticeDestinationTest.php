<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Foundation\Upgrade\UpgradeNotice;
use FundKit\Foundation\Upgrade\UpgradeRoutine;
use FundKit\Foundation\Upgrade\UpgradeRunner;

/**
 * A half-finished data migration leaves totals reading as though nothing were
 * wrong, and this notice is the only thing that reaches someone not looking for
 * it. It pointed at Settings `tab=advanced`, which does not exist: Settings
 * falls back to Setup for an unknown tab, and the notice suppressed itself on
 * every fundkit-settings screen, so following the warning landed the operator on a
 * page saying nothing about it.
 *
 * The screen that does carry it is Tools > Maintenance, which lists each
 * pending routine, what it stopped on, how many times, and the retry.
 */
final class UpgradeNoticeDestinationTest extends IntegrationTestCase
{
    private function noticeHtml(): string
    {
        $routine = new class implements UpgradeRoutine {
            public function id(): string
            {
                return 'probe-routine';
            }

            public function description(): string
            {
                return 'A routine that exists only to leave one outstanding.';
            }

            public function step(): bool
            {
                return true;
            }
        };

        delete_option(UpgradeRunner::OPTION_DONE);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        (new UpgradeNotice(new UpgradeRunner([$routine])))->render();

        return (string) ob_get_clean();
    }

    public function test_the_notice_links_to_the_screen_that_carries_the_detail(): void
    {
        $html = $this->noticeHtml();

        $this->assertStringContainsString('page=fundkit-tools', $html, 'Tools is where pending upgrades are listed');
        $this->assertStringContainsString('#maintenance', $html, 'and Tools reads its tab from the fragment');
        $this->assertStringNotContainsString('tab=advanced', $html, 'Settings has no advanced tab');
    }

    public function test_the_tab_it_links_to_exists(): void
    {
        $tools = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin/tools/Tools.jsx');

        $this->assertStringContainsString(
            "key: 'maintenance'",
            $tools,
            'the fragment must name a real tab, or Tools silently falls back to its first'
        );
    }

    /** It must not hide itself on the page it sends people to. */
    public function test_it_suppresses_itself_on_tools_not_on_settings(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Foundation/Upgrade/UpgradeNotice.php'
        );

        $this->assertStringContainsString("str_contains((string) \$screen->id, 'fundkit-tools')", $source);
        $this->assertStringNotContainsString("str_contains((string) \$screen->id, 'fundkit-settings')", $source);
    }
}
