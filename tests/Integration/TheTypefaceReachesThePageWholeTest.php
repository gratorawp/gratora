<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Styling\CampaignStyleVars;

/**
 * A campaign's look is written on each block as a style attribute, and every
 * block's markup passes through WordPress's filter on the way out. The typeface
 * has to come through it whole on every WordPress the plugin runs on.
 */
final class TheTypefaceReachesThePageWholeTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CampaignStyleVars::flush();

        parent::tearDown();
    }

    /** @return array<string,string> the declarations a filtered wrapper is left with */
    private function declarationsAfterTheFilter(): array
    {
        CampaignStyleVars::flush();

        $html = wp_kses(
            '<div style="' . esc_attr(CampaignStyleVars::forCampaign(null)) . '">x</div>',
            ['div' => ['style' => true]]
        );
        $this->assertSame(1, preg_match('/style="([^"]*)"/', $html, $m), $html);

        $out = [];
        foreach (array_filter(array_map('trim', explode(';', html_entity_decode($m[1], ENT_QUOTES)))) as $declaration) {
            $this->assertStringContainsString(':', $declaration, 'a piece of a broken declaration was left behind: ' . $declaration);
            [$property, $value] = explode(':', $declaration, 2);
            $out[trim($property)] = trim($value);
        }

        return $out;
    }

    private function useTypeface(string $stack): void
    {
        add_filter('gratora.campaign_style.tokens', static fn (array $tokens): array => ['gratora-typeface' => $stack] + $tokens);
    }

    public function test_the_default_stack_comes_through(): void
    {
        $this->assertSame(
            'system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
            $this->declarationsAfterTheFilter()['--gratora-typeface'] ?? null
        );
    }

    public function test_a_stack_typed_with_quotes_comes_through(): void
    {
        $this->useTypeface('"Helvetica Neue", "Segoe UI", Arial, sans-serif');

        $this->assertSame(
            'Helvetica Neue, Segoe UI, Arial, sans-serif',
            $this->declarationsAfterTheFilter()['--gratora-typeface'] ?? null
        );
    }

    // Kept in its quotes where this WordPress lets them through, left out where it does not. Never half of it.
    public function test_a_family_that_cannot_lose_its_quotes_never_breaks_the_rest(): void
    {
        $this->useTypeface('"Source Sans 3", Georgia, serif');

        $typeface = $this->declarationsAfterTheFilter()['--gratora-typeface'] ?? null;

        $this->assertContains($typeface, ['"Source Sans 3", Georgia, serif', 'Georgia, serif']);
    }

    public function test_the_colours_beside_it_are_untouched(): void
    {
        $declarations = $this->declarationsAfterTheFilter();

        $this->assertArrayHasKey('--gratora-accent', $declarations);
        $this->assertArrayHasKey('--gratora-type-size', $declarations);
    }
}
