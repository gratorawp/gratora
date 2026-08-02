<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationNote;
use Dono\Donations\Refund;
use Dono\Donors\Consent;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;
use Dono\Recurring\RecurringPlan;

/**
 * Erasure must reach every table that holds the donor, not just the obvious
 * ones. The QA sweep seeded one donor across 18 tables, ran the real redact
 * route, and found eight still holding the name or email in cleartext.
 *
 * This covers the donor-scoped ones. `webhooks_log.payload` and
 * `events.payload` are NOT donor-scoped and are still open, tracked separately.
 */
final class DonorErasureCompletenessTest extends IntegrationTestCase
{
    private const NEEDLE = 'erasure-needle@example.test';

    private int $donorId;
    private int $donationId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('Y-m-d H:i:s');

        $d = Donor::make();
        $d->email_hash = hash('sha256', self::NEEDLE);
        $d->first_name = 'Needle';
        $d->last_name  = 'Person';
        $d->created_at = $now;
        $d->updated_at = $now;
        $d->save();
        $this->donorId = (int) $d->id;

        $x = Donation::make();
        $x->reference         = 'DONO-ERASE-1';
        $x->donor_id          = $this->donorId;
        $x->amount_cents      = 5000;
        $x->base_amount_cents = 5000;
        $x->currency          = 'EUR';
        $x->base_currency     = 'EUR';
        $x->status            = 'paid';
        $x->gateway           = 'stripe';
        $x->frequency         = 'one_time';
        $x->kind              = 'donation';
        $x->is_test           = false;
        // What the gateway told us about the payer.
        $x->gateway_metadata  = ['payer_email' => self::NEEDLE, 'name' => 'Needle Person'];
        $x->paid_at           = $now;
        $x->created_at        = $now;
        $x->updated_at        = $now;
        $x->save();
        $this->donationId = (int) $x->id;

        $n = DonationNote::make();
        $n->donation_id = $this->donationId;
        $n->body_encrypted = 'Spoke to ' . self::NEEDLE . ' about the gift';
        $n->created_at  = $now;
        $n->updated_at  = $now;
        $n->save();

        $r = Refund::make();
        $r->donation_id  = $this->donationId;
        $r->amount_cents = 1000;
        $r->currency     = 'EUR';
        $r->status       = 'succeeded';
        $r->initiated_by = 'admin';
        $r->gateway_refund_id = 'rf_erase_1';
        $r->reason       = 'Requested by ' . self::NEEDLE;
        $r->metadata     = ['payer_email' => self::NEEDLE];
        $r->occurred_at  = $now;
        $r->save();

        $p = RecurringPlan::make();
        $p->donor_id          = $this->donorId;
        $p->gateway           = 'stripe';
        $p->gateway_subscription_id = 'sub_erase_1';
        $p->gateway_customer_id     = 'cus_erase_needle';
        $p->amount_cents      = 5000;
        $p->currency          = 'EUR';
        $p->interval_unit     = 'month';
        $p->interval_count    = 1;
        $p->status            = 'active';
        $p->is_test           = false;
        $p->started_at        = $now;
        $p->created_at        = $now;
        $p->updated_at        = $now;
        $p->save();

        $consent = Consent::make();
        $consent->donor_id        = $this->donorId;
        $consent->purpose         = 'marketing';
        $consent->granted         = true;
        $consent->source          = 'donation_form';
        $consent->ip_hash         = str_repeat('a', 64);
        $consent->user_agent_hash = str_repeat('b', 64);
        $consent->occurred_at     = $now;
        $consent->save();

        $this->consentId = (int) $consent->id;
    }

    private int $consentId = 0;

    public function test_erasure_keeps_the_consent_but_drops_its_identifying_hashes(): void
    {
        $this->erase();

        $consent = Consent::query()->where('id', $this->consentId)->get();

        $this->assertNotNull($consent, 'the consent fact is lawful-basis evidence and survives');
        $this->assertSame('marketing', $consent->purpose);
        $this->assertTrue((bool) $consent->granted);

        // ip_hash is a salted digest over a space small enough to enumerate and
        // user_agent_hash is unsalted, so both re-link the row to the person.
        $this->assertNull($consent->ip_hash);
        $this->assertNull($consent->user_agent_hash);
    }

    private function erase(): void
    {
        $svc = Plugin::instance()->container->get(DonorService::class);
        $svc->redact(Donor::query()->find('id', $this->donorId));
    }

    public function test_the_gateways_record_of_the_payer_is_cleared(): void
    {
        $this->erase();

        $donation = Donation::query()->find('id', $this->donationId);
        $this->assertNull($donation->gateway_metadata, 'the gateway payer blob is cleartext PII');
    }

    public function test_notes_written_against_a_donation_are_removed(): void
    {
        $this->erase();

        $this->assertNull(
            DonationNote::query()->where('donation_id', $this->donationId)->get(),
            'a donation note is the same free text as a donor note'
        );
    }

    public function test_refund_reason_and_metadata_are_cleared(): void
    {
        $this->erase();

        $refund = Refund::query()->where('donation_id', $this->donationId)->get();
        $this->assertNotNull($refund, 'the financial record itself is retained');
        $this->assertNull($refund->reason);
        $this->assertNull($refund->metadata);
    }

    public function test_the_gateway_customer_handle_is_cleared(): void
    {
        $this->erase();

        $plan = RecurringPlan::query()->where('donor_id', $this->donorId)->get();
        $this->assertNotNull($plan, 'the plan survives; only the re-identifying handle goes');
        $this->assertNull($plan->gateway_customer_id);
    }

    /** The point of all of it: the needle is gone from every table above. */
    public function test_no_donor_scoped_table_still_holds_the_needle(): void
    {
        $this->erase();

        global $wpdb;
        foreach ([
            "SELECT gateway_metadata FROM {$wpdb->prefix}dono_donations WHERE donor_id = %d",
            "SELECT body_encrypted FROM {$wpdb->prefix}dono_donation_notes WHERE donation_id = %d",
            "SELECT CONCAT(COALESCE(reason,''), COALESCE(metadata,'')) FROM {$wpdb->prefix}dono_refunds WHERE donation_id = %d",
            "SELECT gateway_customer_id FROM {$wpdb->prefix}dono_recurring_plans WHERE donor_id = %d",
        ] as $i => $sql) {
            $id  = $i === 0 || $i === 3 ? $this->donorId : $this->donationId;
            $val = (string) implode('', (array) $wpdb->get_col($wpdb->prepare($sql, $id)));
            $this->assertStringNotContainsString(self::NEEDLE, $val, "row {$i} still holds the donor email");
        }
    }

    /** Financial records must survive: erasure is not deletion. */
    public function test_the_financial_record_survives(): void
    {
        $this->erase();

        $donation = Donation::query()->find('id', $this->donationId);
        $this->assertSame(5000, (int) $donation->amount_cents);
        $this->assertSame('DONO-ERASE-1', $donation->reference);
        $this->assertSame('paid', $donation->status);
    }
}
