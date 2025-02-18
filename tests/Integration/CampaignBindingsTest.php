<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Blocks\CampaignBindings;
use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use Dono\Foundation\Plugin;

/**
 * Verifies the `dono/campaign` Block Bindings source resolves campaign stats
 * for keys the bindings docs cover, and falls back to `_dono_campaign_id`
 * post meta when no `campaign_id` arg is supplied.
 */
final class CampaignBindingsTest extends IntegrationTestCase
{
    public function test_resolves_title_and_money_keys(): void
    {
        $campaign = $this->seedCampaign();
        $bindings = $this->bindings();

        $this->assertSame(
            'Save the bees',
            $bindings->resolve(['key' => 'title', 'campaign_id' => $campaign->id], null, 'content')
        );
        $this->assertSame(
            '12300',
            $bindings->resolve(['key' => 'raised_cents', 'campaign_id' => $campaign->id], null, 'content')
        );
        $this->assertStringContainsString(
            '123',
            (string) $bindings->resolve(['key' => 'raised', 'campaign_id' => $campaign->id], null, 'content')
        );
    }

    public function test_resolves_progress_percent_with_clamp(): void
    {
        $campaign = $this->seedCampaign(['raised_cents' => 75000, 'goal_cents' => 50000]);
        $bindings = $this->bindings();

        $this->assertSame(
            '100',
            $bindings->resolve(['key' => 'percent', 'campaign_id' => $campaign->id], null, 'content'),
            'percent clamps to 100 when raised exceeds goal'
        );
        $this->assertSame(
            '100%',
            $bindings->resolve(['key' => 'percent_label', 'campaign_id' => $campaign->id], null, 'content')
        );
    }

    public function test_unknown_key_returns_null(): void
    {
        $campaign = $this->seedCampaign();
        $bindings = $this->bindings();

        $this->assertNull(
            $bindings->resolve(['key' => 'nonsense', 'campaign_id' => $campaign->id], null, 'content')
        );
    }

    public function test_no_campaign_id_falls_back_to_post_meta(): void
    {
        $campaign = $this->seedCampaign();
        $postId   = self::factory()->post->create();
        update_post_meta($postId, '_dono_campaign_id', $campaign->id);

        // Fake block context with postId.
        $block = (object) ['context' => ['postId' => $postId]];

        $value = $this->bindings()->resolve(['key' => 'title'], $block, 'content');
        $this->assertSame('Save the bees', $value);
    }

    public function test_donations_goal_type_uses_count(): void
    {
        $campaign = $this->seedCampaign([
            'goal_type'        => 'donations',
            'goal_count'       => 100,
            'donations_count'  => 25,
        ]);
        $bindings = $this->bindings();

        $this->assertSame('25', $bindings->resolve(['key' => 'donations_count', 'campaign_id' => $campaign->id], null, 'content'));
        $this->assertSame('100', $bindings->resolve(['key' => 'goal', 'campaign_id' => $campaign->id], null, 'content'),
            'donations goal returns raw count, not money');
        $this->assertSame('25', $bindings->resolve(['key' => 'percent', 'campaign_id' => $campaign->id], null, 'content'));
    }

    private function bindings(): CampaignBindings
    {
        return new CampaignBindings(
            Plugin::instance()->container->get(CampaignRepository::class)
        );
    }

    private function seedCampaign(array $overrides = []): Campaign
    {
        $now = '2026-01-01 00:00:00';
        $defaults = [
            'title'           => 'Save the bees',
            'slug'            => 'save-the-bees-' . bin2hex(random_bytes(3)),
            'description'     => 'For pollinators.',
            'status'          => 'active',
            'currency'        => 'USD',
            'goal_type'       => 'amount',
            'goal_cents'      => 50000,
            'goal_count'      => 0,
            'raised_cents'    => 12300,
            'donors_count'    => 5,
            'donations_count' => 7,
            'ends_at'         => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $data = array_merge($defaults, $overrides);

        $campaign = Campaign::make();
        foreach ($data as $k => $v) {
            $campaign->{$k} = $v;
        }
        $campaign->save();
        return $campaign;
    }
}
