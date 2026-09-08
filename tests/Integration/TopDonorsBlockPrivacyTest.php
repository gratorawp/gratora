<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * Hiding a donor is the lever an admin is pointed at when a name has to come
 * off the public page: a harassment case, a doxxing attempt, a donor who asked
 * to be taken down. It has to hold on every block that publishes a name, not
 * just the one it was first written for.
 */
final class TopDonorsBlockPrivacyTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Top donors campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    public function test_a_visible_donor_is_named(): void
    {
        $this->seedDonation($this->donor('visible@example.com'));

        $this->assertStringContainsString('Nadia Petrova', $this->renderBlock());
    }

    public function test_a_hidden_donor_is_not_named(): void
    {
        $donorId = $this->donor('hidden@example.com');
        $this->seedDonation($donorId);
        $this->hide($donorId);

        $html = $this->renderBlock();

        $this->assertStringNotContainsString('Nadia Petrova', $html);
        $this->assertStringContainsString('Anonymous', $html);
    }

    /**
     * The ranking still has to add up: hiding removes the person, not the
     * money, so the amount stays and only the name is masked.
     */
    public function test_a_hidden_donor_still_ranks(): void
    {
        $donorId = $this->donor('hidden2@example.com');
        $this->seedDonation($donorId, 500000);
        $this->hide($donorId);

        $this->assertStringContainsString('5,000', $this->renderBlock());
    }

    public function test_a_hidden_donor_does_not_leak_an_initial(): void
    {
        $donorId = (int) Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate('zoltan@example.com', ['first_name' => 'Zoltan', 'last_name' => 'Quddus'])
            ->id;
        $this->seedDonation($donorId);
        $this->hide($donorId);

        $html = $this->renderBlock();

        $this->assertStringNotContainsString('Zoltan', $html);
        $this->assertStringNotContainsString('>Z<', $html);
    }

    private function hide(int $donorId): void
    {
        $donor = Donor::query()->find('id', $donorId);
        $donor->public_hidden_at = gmdate('Y-m-d H:i:s');
        $donor->save();
    }

    private function donor(string $email): int
    {
        return (int) Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Nadia', 'last_name' => 'Petrova'])
            ->id;
    }

    private function seedDonation(int $donorId, int $amountCents = 12345): void
    {
        $d = Donation::make();
        $d->donor_id     = $donorId;
        $d->campaign_id  = $this->campaignId;
        $d->reference    = 'TOP-' . bin2hex(random_bytes(4));
        $d->amount_cents = $amountCents;
        $d->net_cents    = $amountCents;
        // What the ranking actually sums.
        $d->base_amount_cents = $amountCents;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->is_anonymous = false;
        $d->paid_at      = '2026-08-01 00:00:00';
        $d->created_at   = '2026-08-01 00:00:00';
        $d->updated_at   = '2026-08-01 00:00:00';
        $d->save();
    }

    private function renderBlock(): string
    {
        $pageId = wp_insert_post([
            'post_title'   => 'Top donors page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => sprintf('<!-- wp:fundkit/top-donors {"campaignId":%d} /-->', $this->campaignId),
            'meta_input'   => ['_fundkit_campaign_id' => $this->campaignId],
        ]);

        global $post;
        $post = get_post((int) $pageId);
        setup_postdata($post);
        try {
            return do_blocks($post->post_content);
        } finally {
            wp_reset_postdata();
        }
    }
}
