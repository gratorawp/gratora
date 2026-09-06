<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Receipts\Receipt;
use WP_REST_Request;

/**
 * The list is capped, and the cap was applied before test-mode receipts were
 * filtered out, so a long-standing donor lost live receipts to rows they can
 * never see. It also answered with a bare array and no count, so the screen had
 * no way to say it was showing a slice.
 */
final class PortalReceiptsPagingTest extends IntegrationTestCase
{
    private const PAGE = 200;

    private string $csrf = '';
    private int $donorId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('receipts-' . uniqid() . '@example.test', ['first_name' => 'Ada']);
        $this->donorId = (int) $donor->id;

        $this->csrf = bin2hex(random_bytes(8));
        $_COOKIE['fundkit_donor_session'] = $this->portalSession($this->donorId, $this->csrf);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['fundkit_donor_session']);
        parent::tearDown();
    }

    private function testModeDonation(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference    = 'RCPT-TEST-' . uniqid();
        $d->donor_id     = $this->donorId;
        $d->amount_cents = 1000;
        $d->net_cents    = 1000;
        $d->currency     = 'USD';
        $d->base_amount_cents = 1000;
        $d->base_currency     = 'USD';
        $d->fx_rate      = '1.00000000';
        $d->gateway      = 'offline';
        $d->status       = 'paid';
        $d->is_test      = true;
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        return (int) $d->id;
    }

    private function receipt(int $donationId, string $issuedAt, string $number): void
    {
        $r = Receipt::make();
        $r->donor_id       = $this->donorId;
        $r->donation_id    = $donationId;
        $r->receipt_number = $number;
        $r->renderer_id    = 'generic';
        $r->voided         = false;
        $r->issued_at      = $issuedAt;
        $r->save();
    }

    /** @return array<string,mixed> */
    private function list(): array
    {
        $req = new WP_REST_Request('GET', '/fundkit/v1/portal/receipts');
        $req->set_header('X-FundKit-Csrf', $this->csrf);

        $res = rest_do_request($req);
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        return (array) $res->get_data();
    }

    public function test_a_test_receipt_does_not_take_a_live_one_off_the_page(): void
    {
        $testId = $this->testModeDonation();

        // The newest row is the one the donor may not see, so under the old
        // shape it consumed a slot at the top of the page.
        $this->receipt($testId, '2026-09-01 10:00:00', 'RCPT-TEST-1');
        for ($i = 0; $i < self::PAGE; $i++) {
            $this->receipt(1000 + $i, gmdate('Y-m-d H:i:s', strtotime('2026-08-01 10:00:00') - $i * 3600), 'RCPT-' . $i);
        }

        $data = $this->list();

        $this->assertCount(self::PAGE, $data['items'], 'a full page of live receipts');
        $this->assertSame(self::PAGE, (int) $data['total']);

        $numbers = array_column($data['items'], 'receipt_number');
        $this->assertNotContains('RCPT-TEST-1', $numbers, 'a test receipt is never listed');
    }

    public function test_the_donor_is_told_the_list_is_a_slice(): void
    {
        for ($i = 0; $i < self::PAGE + 6; $i++) {
            $this->receipt(2000 + $i, gmdate('Y-m-d H:i:s', strtotime('2026-08-01 10:00:00') - $i * 3600), 'RCPT-S-' . $i);
        }

        $data = $this->list();

        $this->assertCount(self::PAGE, $data['items']);
        $this->assertSame(self::PAGE + 6, (int) $data['total'], 'the count is what the donor has, not what fits');
    }
}
