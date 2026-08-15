<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * POST /admin/donations: an admin recording money that arrived off the site.
 *
 * Checks, cash in a bucket at an event, a bank transfer nobody told the site
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
     * A check banked in June and entered in August belongs to June. Stamping
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
     * check they sent six weeks ago is the worst thing this feature could do.
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

    /** Silence is the default: an admin entering last month's checks must not fire fifty receipts. */
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
     * mode while an admin enters real checks would void them all, silently,
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
            'a hand-recorded check was flagged as a test donation'
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

    /** A future-dated check is a typo, and it would sit in a period that has not happened. */
    public function test_it_rejects_a_date_in_the_future(): void
    {
        $this->assertSame(400, $this->record(['received_at' => '2099-01-01'])->get_status());
    }

    /**
     * WordPress pins PHP to UTC, so an admin east of the site sees a local date
     * the server would call the future. Without a day of slack an admin in
     * Auckland cannot record today's cash at all, on the untouched default.
     */
    public function test_tomorrow_is_accepted_because_somewhere_it_is_already_tomorrow(): void
    {
        $tomorrow = (new \DateTimeImmutable(current_time('Y-m-d')))->modify('+1 day')->format('Y-m-d');

        $this->assertSame(201, $this->record(['received_at' => $tomorrow])->get_status());
    }

    /** createFromFormat rolls a nonexistent date forward rather than refusing it. */
    public function test_it_rejects_a_day_that_does_not_exist(): void
    {
        $this->assertSame(400, $this->record(['received_at' => '2026-02-30'])->get_status());
    }

    /** A mistyped year lands in the earliest bucket of every time series, unseen. */
    public function test_it_rejects_a_year_from_before_the_product_existed(): void
    {
        $this->assertSame(400, $this->record(['received_at' => '1900-01-01'])->get_status());
    }

    /**
     * M4. Two checks for the same amount from the same donor on the same day
     * are genuinely possible, so this cannot dedupe silently: swallowing a real
     * second donation is worse than the double entry it would prevent. It
     * warns, and names the donation it thinks this is a copy of.
     */
    public function test_it_warns_before_recording_the_same_donation_twice(): void
    {
        $first = (string) $this->record()->get_data()['reference'];

        $res = $this->record();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('dono_duplicate_donation', $res->get_data()['code']);
        $this->assertSame($first, $res->get_data()['data']['reference'], 'the warning must name what it matched');
    }

    /** The admin looked, and they really did give twice. */
    public function test_the_admin_can_record_it_anyway(): void
    {
        $this->record();

        $this->assertSame(201, $this->record(['confirm_duplicate' => true])->get_status());
        $this->assertCount(2, Donation::query()->where('amount_cents', 25000)->getAll());
    }

    public function test_a_different_amount_is_not_a_duplicate(): void
    {
        $this->record();

        $this->assertSame(201, $this->record(['amount_cents' => 25001])->get_status());
    }

    public function test_the_same_amount_on_another_day_is_not_a_duplicate(): void
    {
        $this->record();

        $this->assertSame(201, $this->record(['received_at' => '2026-06-15'])->get_status());
    }

    /**
     * M10. Someone exercised their right to erasure. An admin typing their
     * email is not that person coming back, so the money goes on the books and
     * the erasure holds: no un-redaction, and no name written onto the fresh
     * row that redaction cleared from every other one.
     */
    public function test_recording_a_cheque_does_not_un_erase_a_donor(): void
    {
        $donorId = (int) $this->donation((string) $this->record()->get_data()['reference'])->donor_id;

        $donors = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Donors\DonorService::class);
        $donors->redact(\Dono\Donors\Donor::query()->find('id', $donorId));

        $reference = (string) $this->record(['amount_cents' => 4200])->get_data()['reference'];

        $donor = \Dono\Donors\Donor::query()->find('id', $donorId);
        $this->assertNotNull($donor->redacted_at, 'a hand-recorded check un-erased a donor who asked to be forgotten');

        $donation = $this->donation($reference);
        $this->assertSame($donorId, (int) $donation->donor_id, 'the money still has to be on the books');
        $this->assertSame(4200, (int) $donation->amount_cents);
        $this->assertNull($donation->donor_first_name, 'the erased name came back on the new row');
        $this->assertNull($donation->donor_last_name);
    }

    /**
     * M1. confirm() took paid_at on trust, and donation.confirm forwards a
     * free-form object from the AI assistant straight into it. A garbage
     * timestamp in that column moves real money into a period that never
     * happened, and nothing downstream questions it.
     */
    public function test_an_unusable_paid_at_falls_back_to_the_clock_rather_than_being_stored(): void
    {
        $service = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Donations\DonationService::class);

        foreach (['not a date', '2099-01-01 00:00:00', '1804-05-01 00:00:00'] as $bad) {
            $pending = $service->createPending(new \Dono\Donations\DonationIntent(
                email: 'clock@example.com',
                amount_cents: 1000,
                currency: 'USD',
                gateway: 'offline',
            ))['donation'];

            $paid = $service->confirm($pending, ['paid_at' => $bad]);

            $this->assertNotSame($bad, (string) $paid->paid_at, sprintf('paid_at %s was stored as given', $bad));
            $this->assertStringStartsWith(
                gmdate('Y-m-d'),
                (string) $paid->paid_at,
                sprintf('paid_at %s should have fallen back to the clock', $bad)
            );
        }
    }

    /** A real backdated date still survives it: the guard is a range, not a veto. */
    public function test_a_plausible_backdated_paid_at_is_kept(): void
    {
        $reference = (string) $this->record(['received_at' => '2026-06-14'])->get_data()['reference'];

        $this->assertStringStartsWith('2026-06-14', (string) $this->donation($reference)->paid_at);
    }

    /**
     * M6, the half that bites hardest. A listener throwing after the transition
     * means the money IS on the books, and the old catch reported 500 anyway.
     * The admin, told it failed, enters the same check again.
     *
     * The throw is on a listener rather than inside confirm() because by the
     * time a test can reach a hook the row is already paid, which is precisely
     * the case being pinned. The other half, a throw inside confirm's own
     * transaction leaving the row pending, is handled in the same catch.
     */
    public function test_a_failure_after_the_money_is_recorded_does_not_report_failure(): void
    {
        $boom = static function (): void {
            throw new \RuntimeException('a listener exploded');
        };
        add_action('dono.donation.completed', $boom, 1);

        try {
            $res = $this->record();
        } finally {
            remove_action('dono.donation.completed', $boom, 1);
        }

        $this->assertSame(
            201,
            $res->get_status(),
            'the money was recorded, so reporting failure invites the admin to enter it twice'
        );

        $donation = $this->donation((string) $res->get_data()['reference']);
        $this->assertSame('paid', $donation->status);
        $this->assertSame(25000, (int) $donation->amount_cents);
    }

    /** And nothing is left looking like money the org is still waiting for. */
    public function test_no_donation_is_left_pending_after_a_failure(): void
    {
        $boom = static function (): void {
            throw new \RuntimeException('a listener exploded');
        };
        add_action('dono.donation.completed', $boom, 1);

        try {
            $this->record();
        } finally {
            remove_action('dono.donation.completed', $boom, 1);
        }

        $this->assertSame(
            [],
            Donation::query()->where('status', 'pending')->getAll(),
            'a half-recorded donation was left looking like money owed'
        );
    }

    /**
     * M9. paidWithoutReceipt() feeds the AI assistant's "donors who never got
     * their receipt" report. A check the admin deliberately sent no receipt for
     * matched it forever.
     */
    public function test_a_recorded_donation_is_not_reported_as_a_missing_receipt(): void
    {
        $this->record();

        $missing = \Dono\Foundation\Plugin::instance()->container
            ->get(\Dono\Donations\DonationRepository::class)
            ->paidWithoutReceipt();

        $this->assertSame(0, (int) $missing['total'], 'a hand-recorded check is not a receipt that went missing');
    }

    /** An online donation with no receipt still is one. */
    public function test_an_online_donation_with_no_receipt_is_still_reported(): void
    {
        $service = \Dono\Foundation\Plugin::instance()->container->get(\Dono\Donations\DonationService::class);
        $pending = $service->createPending(new \Dono\Donations\DonationIntent(
            email: 'online@example.com',
            amount_cents: 1000,
            currency: 'USD',
            gateway: 'offline',
        ))['donation'];
        $service->confirm($pending, []);

        $missing = \Dono\Foundation\Plugin::instance()->container
            ->get(\Dono\Donations\DonationRepository::class)
            ->paidWithoutReceipt();

        $this->assertGreaterThan(0, (int) $missing['total']);
    }

    /**
     * M8. The drawer's picker used /admin/campaigns, which needs
     * dono_manage_campaigns: exactly what a role created to enter checks does
     * not have. The catch was empty, so it rendered blank and every donation
     * that role recorded went uncategorised.
     */
    public function test_the_campaign_picker_is_readable_by_someone_who_can_only_record(): void
    {
        $campaignId = $this->aCampaign();

        $user = self::factory()->user->create(['role' => 'subscriber']);
        $wpUser = get_user_by('id', $user);
        $wpUser->add_cap('dono_access');
        $wpUser->add_cap('dono_view_donations');
        $wpUser->add_cap('dono_refund_donations');
        wp_set_current_user($user);

        $res = rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/donations/campaign-options'));

        $this->assertSame(200, $res->get_status());
        $this->assertContains(
            $campaignId,
            array_map(static fn (array $c): int => (int) $c['id'], (array) $res->get_data()),
            'the picker cannot see the campaign the donation belongs to'
        );
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

    /**
     * The public route has always checked the accepted list; this one validated
     * the code as three letters and took whatever it was given. A donation
     * recorded in a currency with no configured rate has no base amount, so it
     * sits outside every total with nothing on any screen saying so.
     */
    public function test_a_currency_the_org_does_not_accept_is_refused(): void
    {
        // The suite accepts USD, EUR and GBP.
        $res = $this->record(['currency' => 'BGN']);

        $this->assertSame(422, $res->get_status());
        $this->assertSame('dono_unsupported_currency', $res->get_data()['code'] ?? null);
        $this->assertSame(0, Donation::query()->where('currency', 'BGN')->count());
    }

    public function test_an_accepted_currency_still_records(): void
    {
        $res = $this->record(['currency' => 'GBP']);

        $this->assertSame(201, $res->get_status());
    }
}
