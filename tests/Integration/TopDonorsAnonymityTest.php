<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * A donation marked anonymous must never surface the donor's name on the
 * public Top Donors block, no matter what the block's hideAnonymous toggle
 * says. Named rankings sum only public donations; anonymous giving shows as
 * one masked aggregate entry.
 */
final class TopDonorsAnonymityTest extends IntegrationTestCase
{
    public function test_anonymous_donations_never_name_the_donor(): void
    {
        $campaign   = $this->createCampaign(['title' => 'Anonymity test']);
        $campaignId = (int) $campaign['id'];

        $this->seedPaidDonation($campaignId, 'alice@example.com', 'Alice', 'Public', 5000, false);
        $this->seedPaidDonation($campaignId, 'bob@example.com', 'Bob', 'Secret', 10000, true);

        $html = $this->renderPage((int) $campaign['page_id']);

        $this->assertStringContainsString('Alice Public', $html, 'public donors are named');
        $this->assertStringNotContainsString('Bob', $html, 'an anonymous donation must not name its donor');
        $this->assertStringNotContainsString('Secret', $html);
        $this->assertStringContainsString('Anonymous', $html, 'anonymous giving shows as a masked aggregate');
    }

    public function test_public_total_excludes_the_donors_anonymous_donations(): void
    {
        $campaign   = $this->createCampaign(['title' => 'Split donor test']);
        $campaignId = (int) $campaign['id'];

        // Same donor: one public donation, one anonymous. The named ranking
        // must carry only the public 2000; the anonymous 7000 lands in the
        // masked aggregate.
        $this->seedPaidDonation($campaignId, 'carol@example.com', 'Carol', 'Split', 2000, false);
        $this->seedPaidDonation($campaignId, 'carol@example.com', 'Carol', 'Split', 7000, true);

        $repo  = Plugin::instance()->container->get(\Dono\Donations\DonationRepository::class);
        $named = $repo->topPaidDonors(null, null, $campaignId, 10, false);
        $anon  = $repo->anonymousPaidTotal(null, null, $campaignId);

        $this->assertCount(1, $named);
        $this->assertSame(2000, $named[0]['amount_cents'], 'named total is the public sum only');
        $this->assertSame(1, $named[0]['donations_count']);
        $this->assertSame(7000, $anon['amount_cents']);
        $this->assertSame(1, $anon['donations_count']);
    }

    private function seedPaidDonation(
        int $campaignId,
        string $email,
        string $first,
        string $last,
        int $cents,
        bool $anonymous
    ): void {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => $first, 'last_name' => $last]);

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference         = 'DONO-ANON-' . substr(md5($email . $cents . (string) $anonymous), 0, 8);
        $d->donor_id          = (int) $donor->id;
        $d->campaign_id       = $campaignId;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_anonymous      = $anonymous;
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /** @param array<string,mixed> $input */
    private function createCampaign(array $input): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) json_encode($input + ['status' => 'published']));
        return rest_do_request($req)->get_data();
    }

    private function renderPage(int $pageId): string
    {
        global $post;
        $post = get_post($pageId);
        setup_postdata($post);
        try {
            return do_blocks($post->post_content);
        } finally {
            wp_reset_postdata();
        }
    }
}
