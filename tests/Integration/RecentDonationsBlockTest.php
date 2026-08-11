<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * What the recent donations list is allowed to publish.
 *
 * Two rules it shares with the rest of the campaign page: an admin who hides a
 * donor has hidden everything a visitor can see of them, and a figure printed
 * beside a name is what the org kept, not what was charged before a refund.
 */
final class RecentDonationsBlockTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode(['title' => 'Recent donations campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    /** Baseline: a donor who opted in is quoted, so the suppression test means something. */
    public function test_an_opted_in_message_is_published(): void
    {
        $donorId = $this->donor('visible@example.com');
        $this->seedDonation($donorId, note: 'Proud to support this', notePublic: true);

        $html = $this->renderBlock();

        $this->assertStringContainsString('Nadia Petrova', $html);
        $this->assertStringContainsString('Proud to support this', $html);
    }

    /**
     * Hiding a donor is the moderation lever an admin is pointed at for an
     * address or an attack left in a public message. It has to take the words
     * down, not just the name above them.
     */
    public function test_hiding_a_donor_takes_their_public_message_down(): void
    {
        $donorId = $this->donor('hidden@example.com');
        $this->seedDonation($donorId, note: 'Come see me at 12 Elm Street', notePublic: true);

        $donor = Donor::query()->find('id', $donorId);
        $donor->public_hidden_at = gmdate('Y-m-d H:i:s');
        $donor->save();

        $html = $this->renderBlock();

        $this->assertStringNotContainsString('Come see me at 12 Elm Street', $html);
        $this->assertStringNotContainsString('Nadia Petrova', $html);
        $this->assertStringContainsString('Anonymous', $html);
    }

    /**
     * Gross would overstate the donation and disagree with the campaign
     * counter and the other donor blocks on the same page, which all net.
     */
    public function test_a_partly_refunded_donation_is_listed_net_of_the_refund(): void
    {
        $donorId = $this->donor('refunded@example.com');
        $this->seedDonation($donorId, amountCents: 12345, refundedCents: 10000);

        $html = $this->renderBlock();

        $this->assertStringContainsString('$23.45', $html);
        $this->assertStringNotContainsString('$123.45', $html);
    }

    public function test_an_unrefunded_donation_is_listed_in_full(): void
    {
        $donorId = $this->donor('full@example.com');
        $this->seedDonation($donorId, amountCents: 12345);

        $this->assertStringContainsString('$123.45', $this->renderBlock());
    }

    private function donor(string $email): int
    {
        return (int) Plugin::instance()->container
            ->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Nadia', 'last_name' => 'Petrova'])
            ->id;
    }

    private function seedDonation(
        int $donorId,
        int $amountCents = 12345,
        int $refundedCents = 0,
        string $note = '',
        bool $notePublic = false,
    ): void {
        $d = Donation::make();
        $d->donor_id       = $donorId;
        $d->campaign_id    = $this->campaignId;
        $d->reference      = 'REC-' . bin2hex(random_bytes(4));
        $d->amount_cents   = $amountCents;
        $d->net_cents      = $amountCents;
        $d->refunded_cents = $refundedCents;
        $d->currency       = 'USD';
        $d->status         = $refundedCents > 0 ? 'partial_refund' : 'paid';
        $d->gateway        = 'offline';
        $d->note_to_org    = $note !== '' ? $note : null;
        $d->note_public    = $notePublic;
        $d->paid_at        = '2026-08-01 00:00:00';
        $d->refunded_at    = $refundedCents > 0 ? '2026-08-02 00:00:00' : null;
        $d->created_at     = '2026-08-01 00:00:00';
        $d->updated_at     = '2026-08-01 00:00:00';
        $d->save();
    }

    private function renderBlock(): string
    {
        $pageId = wp_insert_post([
            'post_title'   => 'Recent donations page',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => sprintf(
                '<!-- wp:dono/recent-donations {"campaignId":%d} /-->',
                $this->campaignId
            ),
            'meta_input'   => ['_dono_campaign_id' => $this->campaignId],
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
