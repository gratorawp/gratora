<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\CampaignService;
use Dono\Donations\Donation;
use Dono\Foundation\Plugin;
use Dono\Funds\FundService;
use WP_REST_Request;

/**
 * The detail endpoint built its payload as shapeDonation(...) + [...], and PHP
 * array union keeps the LEFT value, so the richer campaign and form on the
 * right were thrown away against the nulls on the left. Both read as absent on
 * the screen for as long as the endpoint has existed.
 */
final class DonationDetailRelationsTest extends IntegrationTestCase
{
    private function donationWithRelations(): string
    {
        $c = Plugin::instance()->container->get(CampaignService::class)->create([
            'title' => 'Relations ' . uniqid(), 'slug' => 'rel-' . uniqid(),
            'status' => 'published', 'skip_template' => true,
        ]);
        $f = Plugin::instance()->container->get(FundService::class)->create([
            'code' => 'rel-' . uniqid(), 'name' => 'Restricted water',
        ]);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'REL-' . strtoupper(uniqid());
        $d->donor_id          = 4242;
        $d->campaign_id       = (int) $c->id;
        $d->fund_id           = (int) $f->id;
        $d->amount_cents      = 5000;
        $d->net_cents         = 5000;
        $d->base_amount_cents = 5000;
        $d->base_currency     = 'EUR';
        $d->currency          = 'EUR';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        return (string) $d->reference;
    }

    public function test_the_detail_payload_carries_its_campaign_and_fund(): void
    {
        $ref = $this->donationWithRelations();
        $res = rest_do_request(new WP_REST_Request('GET', "/dono/v1/admin/donations/{$ref}"));
        $this->assertSame(200, $res->get_status());

        $d = ((array) $res->get_data())['donation'];

        $this->assertNotNull($d['campaign'] ?? null, 'the campaign was unioned away');
        $this->assertNotNull($d['fund'] ?? null, 'the fund the donor designated');
        $this->assertSame('Restricted water', $d['fund']['name']);
    }

    public function test_the_list_payload_carries_the_fund(): void
    {
        $this->donationWithRelations();
        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/donations'));

        $rows = (array) $res->get_data();
        $named = array_values(array_filter($rows, static fn ($r): bool => ! empty($r['fund'])));

        $this->assertNotSame([], $named, 'no row carried a fund');
    }
}
