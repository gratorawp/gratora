<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\GiftAid\GiftAidClaimExport;
use Dono\GiftAid\GiftAidClaims;
use Dono\GiftAid\GiftAidDeclaration;
use WP_REST_Request;

/**
 * The claim itself: the record HMRC can ask to see, and the file it goes in.
 *
 * A Gift Aid claim is a reclaim of public money, so the interesting cases here
 * are the ones where a plausible-looking row must NOT reach HMRC, and the one
 * where a record must survive a donor's right to be forgotten.
 */
final class GiftAidClaimTest extends IntegrationTestCase
{
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        // AntiSpamGuard caps donations per email per hour and its transients
        // outlive the per-test transaction, so each test gets its own donor.
        $this->email = 'claim-' . uniqid() . '@example.test';
        update_option('dono_gift_aid', ['enabled' => true]);
        update_option('dono_currency_locale', [
            'default_currency'     => 'GBP',
            'supported_currencies' => ['GBP'],
        ]);
    }

    protected function tearDown(): void
    {
        delete_option('dono_gift_aid');
        parent::tearDown();
    }

    /** @param array<string,mixed> $body */
    private function donate(array $body = []): Donation
    {
        $profile = (array) ($body['profile'] ?? []);
        unset($body['profile']);

        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body + [
            'email'        => $this->email,
            'amount_cents' => 5000,
            'currency'     => 'GBP',
            'gateway'      => 'offline',
            'profile'      => $profile + [
                'first_name' => 'Ada',
                'last_name'  => 'Lovelace',
                'address'    => ['line1' => '14 Acacia Avenue', 'postal' => 'sw1a 1aa', 'country' => 'GB'],
            ],
        ]));

        $res = (array) rest_do_request($req)->get_data();
        $this->assertArrayHasKey('reference', $res, 'donation was not created: ' . wp_json_encode($res));

        return Donation::query()->find('reference', (string) $res['reference']);
    }

    private function claims(): GiftAidClaims
    {
        return Plugin::instance()->container->get(GiftAidClaims::class);
    }

    private function export(): GiftAidClaimExport
    {
        return Plugin::instance()->container->get(GiftAidClaimExport::class);
    }

    /** Marks the gift paid so it reaches the claim file, as a gateway would. */
    private function markPaid(Donation $donation, string $paidAt): void
    {
        Donation::query()
            ->where('id', (int) $donation->id)
            ->update(['status' => 'paid', 'paid_at' => $paidAt]);
    }

    public function test_a_claimable_gift_snapshots_what_hmrc_needs(): void
    {
        $donation = $this->donate(['gift_aid' => true]);

        $claim = $this->claims()->read($donation);

        $this->assertNotNull($claim);
        $this->assertSame('Lovelace', $claim['last_name']);
        $this->assertSame('14', $claim['house'], 'HMRC asks for the house name or number, not the street');
        $this->assertSame('SW1A 1AA', $claim['postcode'], 'normalised, because HMRC matches on it');
    }

    public function test_an_unclaimable_gift_stores_no_identity(): void
    {
        $donation = $this->donate();

        $this->assertNull($donation->gift_aid_claim_encrypted);
    }

    /** The address is evidence for a submitted claim, not a live lookup. */
    public function test_moving_house_does_not_rewrite_a_claim_already_made(): void
    {
        $donation = $this->donate(['gift_aid' => true]);

        $donors = Plugin::instance()->container->get(DonorService::class);
        $donor  = Donor::query()->find('id', (int) $donation->donor_id);
        $donors->editProfile($donor, ['address' => ['line1' => '9 Elsewhere Road', 'postal' => 'M1 1AE']]);

        $claim = $this->claims()->read(Donation::query()->find('id', (int) $donation->id));

        $this->assertSame('14', $claim['house']);
        $this->assertSame('SW1A 1AA', $claim['postcode']);
    }

    public function test_the_claim_file_is_hmrcs_schedule(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, '2026-04-06 09:30:00');

        $built = $this->export()->build('2026-04-01', '2026-04-30 23:59:59');
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $built['csv']))));

        $this->assertSame(GiftAidClaimExport::COLUMNS, str_getcsv($lines[0]));
        $this->assertSame(1, $built['rows']);
        $this->assertSame(5000, $built['amount_cents']);
        $this->assertStringContainsString('Lovelace', $lines[1]);
        $this->assertStringContainsString('SW1A 1AA', $lines[1]);
        $this->assertStringContainsString('06/04/26', $lines[1], 'HMRC wants dd/mm/yy');
        $this->assertStringEndsWith('50.00', $lines[1], 'pounds and pence, not cents');
    }

    /** HMRC rejects a whole schedule on one bad row, so a gap is left out. */
    public function test_a_record_missing_a_postcode_is_skipped_not_half_sent(): void
    {
        $donation = $this->donate([
            'gift_aid' => true,
            'profile'  => ['address' => ['line1' => '14 Acacia Avenue', 'country' => 'GB']],
        ]);
        $this->markPaid($donation, '2026-04-06 09:30:00');

        $built = $this->export()->build('2026-04-01', '2026-04-30 23:59:59');

        $this->assertSame(0, $built['rows']);
        $this->assertSame(1, $built['skipped']);
        $this->assertStringNotContainsString('Lovelace', $built['csv']);
    }

    /** HMRC matches on house plus postcode, so neither alone is a claim. */
    public function test_a_record_missing_a_house_is_skipped_too(): void
    {
        $donation = $this->donate([
            'gift_aid' => true,
            'profile'  => ['address' => ['postal' => 'SW1A 1AA', 'country' => 'GB']],
        ]);
        $this->markPaid($donation, '2026-04-06 09:30:00');

        $built = $this->export()->build('2026-04-01', '2026-04-30 23:59:59');

        $this->assertSame(0, $built['rows']);
        $this->assertSame(1, $built['skipped']);
    }

    /**
     * The export is the last gate before HMRC, so it re-checks rather than
     * trusting a stamp that could have been set under other conditions.
     */
    public function test_a_gift_that_became_test_mode_never_reaches_hmrc(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, '2026-04-06 09:30:00');
        Donation::query()->where('id', (int) $donation->id)->update(['is_test' => 1]);

        $this->assertSame(0, $this->export()->build('2026-04-01', '2026-04-30 23:59:59')['rows']);
    }

    /** Money returned to the donor was never a gift. */
    public function test_a_refunded_gift_is_not_claimed(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, '2026-04-06 09:30:00');
        Donation::query()->where('id', (int) $donation->id)->update(['refunded_cents' => 5000]);

        $built = $this->export()->build('2026-04-01', '2026-04-30 23:59:59');

        $this->assertSame(0, $built['rows']);
        $this->assertSame(1, $built['skipped']);
    }

    /** A part-refund leaves a real gift behind, and the claim follows it down. */
    public function test_a_part_refunded_gift_is_claimed_on_what_the_donor_kept_giving(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, '2026-04-06 09:30:00');
        Donation::query()->where('id', (int) $donation->id)->update(['refunded_cents' => 2000]);

        $built = $this->export()->build('2026-04-01', '2026-04-30 23:59:59');

        $this->assertSame(1, $built['rows']);
        $this->assertSame(3000, $built['amount_cents']);
        $this->assertStringContainsString('30.00', $built['csv']);
    }

    public function test_a_gift_outside_the_period_is_not_in_the_claim(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, '2026-03-31 23:59:59');

        $this->assertSame(0, $this->export()->build('2026-04-01', '2026-04-30 23:59:59')['rows']);
    }

    public function test_hmrc_repays_a_quarter_of_what_was_given(): void
    {
        // 20% basic rate on the gross gift is 25% of the amount received.
        $this->assertSame(2500, GiftAidClaimExport::reclaimCents(10000));
        $this->assertSame(25, GiftAidClaimExport::reclaimCents(100));
    }

    /**
     * The carve-out: erasure clears everything that is not evidence, and keeps
     * the evidence itself for as long as HMRC can ask about it.
     */
    public function test_erasure_keeps_the_claim_inside_hmrcs_retention_window(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, gmdate('Y-m-d H:i:s'));
        $donorId = (int) $donation->donor_id;

        GiftAidDeclaration::query()
            ->where('donor_id', $donorId)
            ->update(['ip_hash' => 'abc', 'user_agent_hash' => 'def']);

        Plugin::instance()->container->get(DonorService::class)
            ->redact(Donor::query()->find('id', $donorId));

        $after = Donation::query()->find('id', (int) $donation->id);
        $this->assertNotNull($after->gift_aid_claim_encrypted, 'a claim the charity has been paid for must stay provable');
        $this->assertSame('Lovelace', $this->claims()->read($after)['last_name']);

        $declaration = GiftAidDeclaration::query()->where('donor_id', $donorId)->orderBy('id', 'DESC')->limit(1)->get();
        $this->assertNull($declaration->ip_hash, 'the network fingerprints prove nothing to HMRC, so they go now');
        $this->assertNull($declaration->user_agent_hash);
    }

    public function test_erasure_clears_the_claim_once_hmrc_can_no_longer_ask(): void
    {
        $donation = $this->donate(['gift_aid' => true]);
        $this->markPaid($donation, gmdate('Y-m-d H:i:s', strtotime('-7 years')));
        $donorId = (int) $donation->donor_id;

        Plugin::instance()->container->get(DonorService::class)
            ->redact(Donor::query()->find('id', $donorId));

        $this->assertNull(
            Donation::query()->find('id', (int) $donation->id)->gift_aid_claim_encrypted,
            'past retention the legal obligation is spent, so the right to erasure wins'
        );
    }

    public function test_the_claim_file_is_export_gated(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $req = new WP_REST_Request('GET', '/dono/v1/admin/gift-aid/export');
        $req->set_param('from', '2026-04-01');
        $req->set_param('to', '2026-04-30');

        $this->assertSame(403, rest_do_request($req)->get_status(), 'the file is bulk PII with home addresses in it');
    }

    /** An operator sees the gaps before they submit, not after HMRC bounces it. */
    public function test_the_summary_reports_what_the_claim_would_contain(): void
    {
        $good = $this->donate(['gift_aid' => true]);
        $this->markPaid($good, '2026-04-06 09:30:00');
        $bad = $this->donate(['profile' => ['address' => ['line1' => '14 Acacia Avenue', 'country' => 'GB']]]);
        Donation::query()->where('id', (int) $bad->id)->update(['gift_aid' => 1]);
        $this->markPaid($bad, '2026-04-07 09:30:00');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('GET', '/dono/v1/admin/gift-aid/summary');
        $req->set_param('from', '2026-04-01');
        $req->set_param('to', '2026-04-30');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $data = (array) $res->get_data();
        $this->assertSame(1, $data['rows']);
        $this->assertSame(1, $data['skipped']);
        $this->assertSame(5000, $data['amount_cents']);
        $this->assertSame(1250, $data['reclaim_cents']);
        $this->assertSame('2026-04-30 23:59:59', $data['to'], 'a bare end date means the whole of that day');
    }
}
