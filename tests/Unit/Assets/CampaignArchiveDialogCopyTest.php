<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The archive dialog is where an admin authorises cancelling a campaign's
 * recurring donors, and the only thing they read before ticking the box is this
 * copy. The figure behind it is every live plan, paused and past_due included,
 * so copy that calls them active understates what is about to be cancelled and
 * copy that says they renew for the monthly total overstates it: a paused plan
 * renews for nothing this month and a past_due one is failing to.
 *
 * @since 1.0.0
 */
final class CampaignArchiveDialogCopyTest extends TestCase
{
    /** The archive modal alone, so a word used elsewhere on the screen is not read as this dialog's. */
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/admin/campaigns/Detail.jsx';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match('/\{ archivePrompt && \(.*?<\/Modal>/s', $src, $m),
            'the archive dialog is still rendered from an archivePrompt guard.'
        );

        return $m[0];
    }

    public function test_the_count_is_not_described_as_active(): void
    {
        $src = $this->source();

        $this->assertStringContainsString(
            'This campaign has %d live recurring donation',
            $src,
            'the dialog names the set it counts.'
        );
        $this->assertStringNotContainsString(
            'active recurring donation',
            $src,
            'the figure includes paused and past_due plans, so it is not an active count.'
        );
    }

    public function test_the_dialog_says_which_statuses_it_counted(): void
    {
        $src = $this->source();

        $this->assertMatchesRegularExpression(
            '/Live counts active, paused and past-due donations/',
            $src,
            'an admin cannot check the number against a screen unless the dialog says what is in it.'
        );
    }

    public function test_the_monthly_figure_is_not_stated_as_what_they_renew_for(): void
    {
        $src = $this->source();

        $this->assertStringNotContainsString(
            'renew',
            $src,
            'part of the total belongs to plans that are not renewing, so nothing here may promise a renewal.'
        );
        $this->assertStringContainsString(
            'About %s a month is at stake.',
            $src,
            'the monthly total is the value at risk, and it is approximate.'
        );
    }
}
