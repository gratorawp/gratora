<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Campaigns\Campaign;
use FundKit\Donations\Donation;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Vendor\Queryable\DB;
use InvalidArgumentException;

/**
 * Deleting a donor takes their dead donations with them. Nothing it removes
 * has ever reached an aggregate, so no total may move.
 */
final class DonorDeleteCascadeTest extends IntegrationTestCase
{
    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donor(): Donor
    {
        return $this->service()->findOrCreate('cascade-' . uniqid() . '@example.test', ['first_name' => 'Cas']);
    }

    private function donation(int $donorId, array $attrs = []): Donation
    {
        $old = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);

        $d = Donation::make();
        $d->reference         = 'CAS-' . uniqid();
        $d->donor_id          = $donorId;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'EUR';
        $d->base_currency     = 'EUR';
        $d->status            = 'failed';
        $d->gateway           = 'stripe';
        $d->frequency         = 'one_time';
        $d->kind              = 'donation';
        $d->is_test           = false;
        $d->created_at        = $old;
        $d->updated_at        = $old;
        foreach ($attrs as $k => $v) {
            $d->{$k} = $v;
        }
        $d->save();

        return $d;
    }

    public function test_the_dead_donations_go_with_the_donor(): void
    {
        $donor = $this->donor();
        $id    = (int) $donor->id;
        $one   = (int) $this->donation($id)->id;
        $two   = (int) $this->donation($id)->id;

        $this->service()->delete($donor);

        $this->assertSame(
            0,
            (int) Donation::query()->whereIn('id', [$one, $two])->count(),
            'a donation pointing at a donor who is gone is an orphan'
        );
        $this->assertNull(Donor::query()->find('id', $id));
    }

    /** The guardrail: this fails the day someone widens the gate to a paid status. */
    public function test_no_aggregate_moves(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title      = 'Cascade';
        $c->slug       = 'cascade-' . uniqid();
        $c->status     = 'active';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();
        $campaignId = (int) $c->id;

        $giver = $this->donor();
        $paid  = $this->donation((int) $giver->id, [
            'status'      => 'paid',
            'paid_at'     => $now,
            'campaign_id' => $campaignId,
        ]);

        Plugin::instance()->container->get(\FundKit\Donations\AggregateSyncer::class)->syncCampaign($campaignId);
        $before = (array) DB::table('fundkit_campaigns')->where('id', $campaignId)->get();

        $doomed = $this->donor();
        $this->donation((int) $doomed->id, ['campaign_id' => $campaignId]);
        $this->service()->delete($doomed);

        Plugin::instance()->container->get(\FundKit\Donations\AggregateSyncer::class)->syncCampaign($campaignId);
        $after = (array) DB::table('fundkit_campaigns')->where('id', $campaignId)->get();

        foreach (['raised_cents', 'donations_count', 'donors_count'] as $col) {
            $this->assertSame(
                $before[$col] ?? null,
                $after[$col] ?? null,
                "campaign {$col} moved, so the delete removed money that had been counted"
            );
        }

        $this->assertNotNull(Donation::query()->find('id', (int) $paid->id), 'the giver keeps their donation');
    }

    public function test_add_ons_are_told_which_donations_are_going(): void
    {
        $donor = $this->donor();
        $did   = (int) $this->donation((int) $donor->id)->id;

        $seen = [];
        add_action('fundkit.test_data.purge_donations', static function (array $ids) use (&$seen): void {
            $seen = $ids;
        });

        $this->service()->delete($donor);

        $this->assertSame([$did], array_map('intval', $seen));
    }

    public function test_a_receipt_stops_the_delete_even_on_a_dead_donation(): void
    {
        $donor = $this->donor();
        $did   = (int) $this->donation((int) $donor->id)->id;

        DB::table('fundkit_receipts')->insert([
            'donation_id'    => $did,
            'donor_id'       => (int) $donor->id,
            'renderer_id'    => 'default',
            'locale'         => 'en_US',
            'receipt_number' => 'REC-CASCADE-' . uniqid(),
            'issued_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->delete($donor);
    }
}
