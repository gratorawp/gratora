<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * POST /admin/donations: an admin recording money that arrived off the site.
 *
 * Cheques, cash in a bucket at an event, a bank transfer nobody told the site
 * about. Until this existed the only way in was an admin filling in the public
 * form as the donor, which runs the anti-spam gates and emails the donor
 * instructions to pay something they had already paid.
 *
 * Every assertion here is about the books being right afterwards. A recorded
 * donation has to be the same kind of row as a donated one, or the totals it
 * feeds are the only place the difference shows up.
 */
final class AdminManualDonationTest extends IntegrationTestCase
{
    /** @param array<string,mixed> $body */
    private function record(array $body = []): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(array_merge([
            'email'          => 'nadia@example.com',
            'first_name'     => 'Nadia',
            'last_name'      => 'Okonjo',
            'amount_cents'   => 25000,
            'currency'       => 'USD',
            'payment_method' => 'cheque',
            'received_at'    => '2026-06-14',
        ], $body)));

        return rest_do_request($req);
    }

    private function donation(string $reference): ?Donation
    {
        return Donation::query()->find('reference', $reference);
    }

    public function test_it_records_a_cheque_as_a_paid_donation(): void
    {
        $res = $this->record();

        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donation = $this->donation((string) $res->get_data()['reference']);
        $this->assertNotNull($donation);
        $this->assertSame('paid', $donation->status);
        $this->assertSame(25000, (int) $donation->amount_cents);
        $this->assertSame('USD', $donation->currency);
        $this->assertSame('offline', $donation->gateway);
        $this->assertSame('cheque', $donation->payment_method);
    }

    /**
     * A cheque banked in June and entered in August belongs to June. Stamping
     * it with the clock puts the money in the wrong month, which is wrong in
     * the campaign total, wrong in the year-end summary, and invisible.
     */
    public function test_the_donation_is_dated_when_the_money_arrived(): void
    {
        $reference = (string) $this->record(['received_at' => '2026-06-14'])->get_data()['reference'];

        $donation = $this->donation($reference);

        $this->assertStringStartsWith('2026-06-14', (string) $donation->paid_at);
        $this->assertStringStartsWith('2026-06-14', (string) $donation->created_at);
    }

    /**
     * The offline gateway emails bank details and payment instructions when a
     * donation intent is created, which is right for a donor who chose to pay
     * offline and wrong for money already banked. Asking someone to send a
     * cheque they sent six weeks ago is the worst thing this feature could do.
     */
    public function test_it_does_not_ask_the_donor_to_pay_again(): void
    {
        $mails = $this->captureMails();

        // send_receipt on purpose: with mail suppressed entirely this would
        // pass without proving anything. Mail has to be flowing for the
        // absence of the instructions email to mean something.
        $this->record(['send_receipt' => true]);
        $this->runPendingAsyncJobs();

        $this->assertGreaterThan(0, count($mails), 'no mail at all, so this proves nothing');

        foreach ($mails as $mail) {
            $this->assertStringNotContainsStringIgnoringCase(
                'instruction',
                (string) ($mail['subject'] ?? '') . ' ' . (string) ($mail['message'] ?? ''),
                'a recorded donation emailed the donor payment instructions'
            );
        }
    }

    /** Silence is the default: an admin entering last month's cheques must not fire fifty receipts. */
    public function test_no_receipt_unless_the_admin_asks_for_one(): void
    {
        $mails = $this->captureMails();

        $this->record();
        $this->runPendingAsyncJobs();

        $this->assertCount(0, $mails, 'recording a donation sent mail nobody asked for');
    }

    public function test_a_receipt_is_issued_when_the_admin_asks(): void
    {
        $mails = $this->captureMails();

        $this->record(['send_receipt' => true]);
        $this->runPendingAsyncJobs();

        $this->assertGreaterThan(0, count($mails), 'send_receipt was ignored');
    }

    /**
     * Test mode excludes a donation from every report. A site left in test
     * mode while an admin enters real cheques would void them all, silently,
     * and the admin would find out at year end.
     */
    public function test_real_money_is_recorded_even_while_the_site_is_in_test_mode(): void
    {
        update_option('dono_gateway_config', array_merge(
            (array) get_option('dono_gateway_config', []),
            ['test_mode' => true]
        ));

        $reference = (string) $this->record()->get_data()['reference'];

        $this->assertFalse(
            (bool) $this->donation($reference)->is_test,
            'a hand-recorded cheque was flagged as a test donation'
        );
    }

    /** It is the same kind of row as a donated one, so the same totals move. */
    public function test_it_moves_the_campaign_total(): void
    {
        $campaignId = $this->aCampaign();

        $before = (int) \Dono\Campaigns\Campaign::query()->find('id', $campaignId)->raised_cents;
        $this->record(['campaign_id' => $campaignId, 'amount_cents' => 25000]);
        $after = (int) \Dono\Campaigns\Campaign::query()->find('id', $campaignId)->raised_cents;

        $this->assertSame($before + 25000, $after);
    }

    /**
     * Not 'direct'. A hand-recorded donation never had a web session, so
     * letting it fall through to the same bucket as an untagged visit
     * overstates how much the website itself brought in.
     */
    public function test_it_is_reported_as_its_own_channel(): void
    {
        $reference = (string) $this->record()->get_data()['reference'];

        $this->assertSame(
            'manual',
            \Dono\Donations\ChannelClassifier::classify(
                (array) $this->donation($reference)->source_attribution
            )
        );
    }

    public function test_it_reuses_an_existing_donor_rather_than_making_a_second_one(): void
    {
        $first  = (string) $this->record()->get_data()['reference'];
        $second = (string) $this->record(['amount_cents' => 5000])->get_data()['reference'];

        $this->assertSame(
            (int) $this->donation($first)->donor_id,
            (int) $this->donation($second)->donor_id
        );
    }

    public function test_it_rejects_a_donation_with_no_amount(): void
    {
        $this->assertSame(400, $this->record(['amount_cents' => 0])->get_status());
    }

    public function test_it_rejects_a_payment_method_the_offline_gateway_does_not_offer(): void
    {
        $this->assertSame(400, $this->record(['payment_method' => 'bitcoin'])->get_status());
    }

    /** A future-dated cheque is a typo, and it would sit in a period that has not happened. */
    public function test_it_rejects_a_date_in_the_future(): void
    {
        $this->assertSame(400, $this->record(['received_at' => '2099-01-01'])->get_status());
    }

    public function test_it_is_closed_to_users_who_cannot_manage_donations(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->record()->get_status());
    }

    private function aCampaign(): int
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Manual entry', 'status' => 'published']));

        return (int) rest_do_request($req)->get_data()['id'];
    }
}
