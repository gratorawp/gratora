<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\Shortcode\DonationFormShortcode;

/**
 * A visitor who follows a link to a campaign that has closed sees whatever this
 * renders. It used to be the reason code itself, so the page said "ended", and
 * only to an administrator: everyone else got an empty div.
 */
final class ClosedCampaignFormMessageTest extends IntegrationTestCase
{
    private function render(?string $reason): string
    {
        $m = new \ReflectionMethod(DonationFormShortcode::class, 'renderNotAccepting');
        $m->setAccessible(true);

        $shortcode = (new \ReflectionClass(DonationFormShortcode::class))->newInstanceWithoutConstructor();

        return (string) $m->invoke($shortcode, $reason);
    }

    public function test_a_finished_campaign_thanks_the_visitor(): void
    {
        $out = $this->render('ended');

        $this->assertStringContainsString('finished accepting donations', $out);
        $this->assertStringNotContainsString('>ended<', $out);
    }

    public function test_a_campaign_that_hit_its_goal_says_so(): void
    {
        $this->assertStringContainsString('reached its goal', $this->render('goal_met'));
    }

    public function test_a_campaign_that_has_not_opened_says_check_back(): void
    {
        $this->assertStringContainsString('not open for donations yet', $this->render('scheduled'));
    }

    /**
     * These three never print a bare reason code, which is the bug: the reason
     * was passed straight through as the message.
     */
    public function test_no_reason_code_reaches_the_page(): void
    {
        foreach (['ended', 'goal_met', 'scheduled'] as $reason) {
            $this->assertStringNotContainsString($reason, $this->render($reason), "{$reason} leaked into the message");
        }
    }

    /**
     * A draft or archived campaign is someone's unfinished work, not a closed
     * appeal, so it stays admin-only rather than announcing itself to visitors.
     */
    public function test_an_unfinished_campaign_says_nothing_to_a_visitor(): void
    {
        wp_set_current_user(0);

        foreach (['draft', 'archived', null] as $reason) {
            $this->assertSame('', $this->render($reason), var_export($reason, true) . ' should stay hidden');
        }
    }

    public function test_an_unfinished_campaign_tells_an_administrator_why(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $out = $this->render('draft');

        $this->assertStringContainsString('Publish the campaign', $out);

        wp_set_current_user(0);
    }
}
