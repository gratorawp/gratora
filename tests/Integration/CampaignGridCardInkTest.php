<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Blocks\CampaignGridBlock;
use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Campaigns\Styling\CampaignStyleVars;
use Gratora\Foundation\Plugin;

/**
 * A grid card paints the card ground of the page it sits on and its own
 * campaign's accent. The accent drawn as text and on its tint was measured for
 * neither: a navy link on a navy card, a pale percentage on a pale pill.
 */
final class CampaignGridCardInkTest extends IntegrationTestCase
{
    /**
     * @param array<string,string> $tokens
     */
    private function campaign(string $title, array $tokens): Campaign
    {
        $now = gmdate('Y-m-d H:i:s');
        $c = Campaign::make();
        $c->title      = $title;
        $c->slug       = 'grid-ink-' . uniqid();
        $c->status     = 'published';
        $c->currency   = 'USD';
        $c->style      = ['tokens' => $tokens];
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        CampaignStyleVars::flush();

        return $c;
    }

    private function grid(array $attrs): string
    {
        $block = new CampaignGridBlock(Plugin::instance()->container->get(CampaignRepository::class));

        return $block->render($attrs + ['count' => 12], '');
    }

    /** The style attribute of the card that names this title. */
    private function cardStyle(string $html, string $title): string
    {
        $pattern = '#<a class="gratora-campaign-card[^"]*"[^>]*?style="([^"]*)">(?:(?!</a>).)*?'
            . preg_quote($title, '#') . '#s';
        $this->assertSame(1, preg_match($pattern, $html, $m), 'no card for ' . $title);

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function test_an_accent_that_vanishes_on_the_card_stands_down_to_card_ink(): void
    {
        $host = $this->campaign('Host ' . uniqid(), ['gratora-bg' => '#15142b']);
        $this->campaign('Deep ' . ($t = uniqid()), ['gratora-accent' => '#0f3d5c']);

        $style = $this->cardStyle($this->grid(['campaignId' => (int) $host->id]), 'Deep ' . $t);

        $this->assertStringContainsString('--gratora-accent:#0f3d5c;', $style);
        $this->assertStringContainsString('--gratora-accent-soft:initial;', $style);
        $this->assertStringContainsString('--gratora-on-bg-accent:var(--gratora-on-bg);', $style);
    }

    public function test_an_accent_that_reads_on_the_card_is_kept(): void
    {
        $host = $this->campaign('Host ' . uniqid(), ['gratora-bg' => '#15142b']);
        $this->campaign('Pale ' . ($t = uniqid()), ['gratora-accent' => '#fde68a']);

        $style = $this->cardStyle($this->grid(['campaignId' => (int) $host->id]), 'Pale ' . $t);

        $this->assertStringContainsString('--gratora-on-bg-accent:var(--gratora-accent);', $style);
        $this->assertStringContainsString('--gratora-on-accent-soft:var(--gratora-accent);', $style);
    }

    /** With no campaign behind the page, the card is the stylesheet's white. */
    public function test_a_pale_card_on_a_page_with_no_campaign_takes_dark_ink_on_its_tint(): void
    {
        $this->campaign('Pale ' . ($t = uniqid()), ['gratora-accent' => '#fde68a']);

        $style = $this->cardStyle($this->grid([]), 'Pale ' . $t);

        $this->assertStringContainsString('--gratora-on-accent-soft:#10162a;', $style);
        $this->assertStringContainsString('--gratora-on-bg-accent:var(--gratora-on-bg);', $style);
    }
}
