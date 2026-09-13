<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationRepository;
use Gratora\Gateways\GatewayLabels;

/**
 * A campaign's By payment method panel had the slug and let CSS put a capital
 * on it, which spells PayPal wrong wherever it is shown.
 */
final class CampaignBreakdownNamesTheGatewayTest extends IntegrationTestCase
{
    private function paidOn(int $campaignId, string $gateway): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->reference         = 'DON-' . strtoupper(bin2hex(random_bytes(4)));
        $d->donor_id          = 1;
        $d->campaign_id       = $campaignId;
        $d->kind              = 'donation';
        $d->status            = 'paid';
        $d->gateway           = $gateway;
        $d->amount_cents      = 2500;
        $d->base_amount_cents = 2500;
        $d->currency          = 'USD';
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    private function campaign(): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $c = Campaign::make();
        $c->title      = 'Breakdown ' . bin2hex(random_bytes(3));
        $c->slug       = 'bd-' . bin2hex(random_bytes(4));
        $c->status     = 'published';
        $c->created_at = $now;
        $c->updated_at = $now;
        $c->save();

        return (int) $c->id;
    }

    public function test_the_breakdown_carries_the_gateways_own_name(): void
    {
        $id = $this->campaign();
        $this->paidOn($id, 'paypal');

        $rows = (new DonationRepository())->aggregatePaidByGateway(null, null, $id);

        $this->assertSame('PayPal', $rows[0]['gateway_label']);
        $this->assertNotSame($rows[0]['gateway'], $rows[0]['gateway_label'], 'the slug is not the name');
    }

    /** Every row carries one, so the screen never has to invent it. */
    public function test_every_row_is_named(): void
    {
        $id = $this->campaign();
        foreach (['paypal', 'stripe', 'offline'] as $gateway) {
            $this->paidOn($id, $gateway);
        }

        $rows = (new DonationRepository())->aggregatePaidByGateway(null, null, $id);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(GatewayLabels::for($row['gateway']), $row['gateway_label']);
            $this->assertNotSame('', $row['gateway_label']);
        }
    }
}
