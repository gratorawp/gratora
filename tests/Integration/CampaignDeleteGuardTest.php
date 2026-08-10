<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
use RuntimeException;

/**
 * A campaign with donations must never be hard-deleted: its donation rows would
 * be orphaned against a missing campaign_id and lose that campaign's reporting.
 * Mirrors the FundService reference guard; the REST layer turns the thrown
 * RuntimeException into a 422.
 */
final class CampaignDeleteGuardTest extends IntegrationTestCase
{
    private function service(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    public function test_campaign_without_donations_can_be_deleted(): void
    {
        $c = $this->service()->create(['title' => 'Empty Drive']);
        $this->service()->delete($c);
        $this->assertNull(Campaign::query()->where('id', $c->id)->get());
    }

    public function test_campaign_with_donations_cannot_be_deleted(): void
    {
        $c = $this->service()->create(['title' => 'Funded Drive']);
        $this->seedDonation((int) $c->id);

        try {
            $this->service()->delete($c);
            $this->fail('Expected a RuntimeException for a campaign with donations');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Archive', $e->getMessage());
        }

        $this->assertNotNull(
            Campaign::query()->where('id', $c->id)->get(),
            'the campaign survives the blocked delete'
        );
    }

    private function seedDonation(int $campaignId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-CG-' . bin2hex(random_bytes(3));
        $d->donor_id          = 1;
        $d->campaign_id       = $campaignId;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->currency          = 'USD';
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /**
     * The screen has to ask before it offers the action, and it has to get the
     * same answer the delete itself would give.
     */
    public function test_the_reason_the_screen_shows_is_the_reason_delete_throws(): void
    {
        $c = $this->service()->create(['title' => 'Asked Twice']);
        $this->assertNull($this->service()->deleteBlockedReason($c), 'nothing blocks an empty campaign');

        $this->seedDonation((int) $c->id);
        $blocked = $this->service()->deleteBlockedReason($c);
        $this->assertNotNull($blocked);

        try {
            $this->service()->delete($c);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame($blocked, $e->getMessage(), 'one rule, one answer');
        }
    }

    /**
     * campaign->donations_count is synced over paid, live, non-ticket rows, so
     * a campaign whose only donation is pending reads zero there while the
     * delete still refuses. Answering the screen from that counter would offer
     * a delete that can only fail, which is what it used to do.
     *
     * @dataProvider invisibleDonations
     */
    public function test_a_donation_the_counter_ignores_still_blocks_the_delete(string $field, $value): void
    {
        $c = $this->service()->create(['title' => 'Counter Says Zero']);
        $this->seedDonation((int) $c->id);

        Donation::query()->where('campaign_id', (int) $c->id)->update([$field => $value]);

        $fresh = Campaign::query()->where('id', (int) $c->id)->get();
        $this->assertSame(0, (int) $fresh->donations_count, 'the counter cannot see it');
        $this->assertNotNull($this->service()->deleteBlockedReason($fresh), 'the gate can');
    }

    /** @return array<string,array{0:string,1:mixed}> */
    public function invisibleDonations(): array
    {
        return [
            'a pending donation'  => ['status', 'pending'],
            'a failed donation'   => ['status', 'failed'],
            'a test donation'     => ['is_test', 1],
            'a ticket order'      => ['kind', 'order'],
        ];
    }
}
