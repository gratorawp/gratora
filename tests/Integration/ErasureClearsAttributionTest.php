<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\ChannelClassifier;
use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * `source_attribution` is filled from the donor's own browser: the page they
 * landed on, verbatim, and the page that sent them. An appeal mailed through
 * an ESP appends a per-recipient identifier to its links, so the landing URL
 * names the individual at that ESP, and it sits on the row next to the amount
 * and the date long after the erasure said it was done.
 *
 * What the channel rollups read is utm_source and utm_medium, so the donation
 * keeps the channel it is counted under.
 */
final class ErasureClearsAttributionTest extends IntegrationTestCase
{
    private const NEEDLE = 'mc_eid=b7f21c9a4e';

    private int $donorId;
    private int $donationId;

    protected function setUp(): void
    {
        parent::setUp();

        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('attribution-' . uniqid() . '@example.test', ['first_name' => 'Robin']);
        $this->donorId = (int) $donor->id;

        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference          = 'DONO-ATTR-' . uniqid();
        $d->donor_id           = $this->donorId;
        $d->amount_cents       = 4000;
        $d->base_amount_cents  = 4000;
        $d->currency           = 'USD';
        $d->base_currency      = 'USD';
        $d->status             = 'paid';
        $d->gateway            = 'offline';
        $d->frequency          = 'one_time';
        $d->kind               = 'donation';
        $d->is_test            = false;
        $d->source_attribution = [
            'utm_source' => 'mailchimp',
            'utm_medium' => 'email',
            'referrer'   => 'https://mail.example.com/click?u=8213&' . self::NEEDLE,
            'landing'    => 'https://charity.example/appeal/?utm_source=mailchimp&' . self::NEEDLE,
        ];
        $d->failure_reason     = 'Gateway createIntent threw: card declined for robin@example.test';
        $d->paid_at            = $now;
        $d->created_at         = $now;
        $d->updated_at         = $now;
        $d->save();
        $this->donationId = (int) $d->id;
    }

    private function erase(): void
    {
        Plugin::instance()->container->get(DonorService::class)
            ->redact(Donor::query()->find('id', $this->donorId));
    }

    private function donation(): Donation
    {
        return Donation::query()->find('id', $this->donationId);
    }

    public function test_the_url_the_donor_arrived_on_does_not_survive_the_erasure(): void
    {
        $this->erase();

        $attribution = (array) $this->donation()->source_attribution;

        $this->assertArrayNotHasKey('landing', $attribution);
        $this->assertArrayNotHasKey('referrer', $attribution);
    }

    public function test_the_row_no_longer_carries_the_identifier_the_link_named_them_by(): void
    {
        $this->erase();

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT CONCAT(COALESCE(source_attribution,''), COALESCE(failure_reason,'')) "
            . "FROM {$wpdb->prefix}dono_donations WHERE id = %d",
            $this->donationId
        ));

        $this->assertStringNotContainsString(self::NEEDLE, $stored);
    }

    /** Raw gateway error text quotes back what was submitted. */
    public function test_the_failure_reason_is_cleared(): void
    {
        $this->erase();

        $this->assertNull($this->donation()->failure_reason);
    }

    /** The donation still counts under the channel that brought it in. */
    public function test_the_channel_the_donation_is_reported_under_survives(): void
    {
        $this->erase();

        $attribution = (array) $this->donation()->source_attribution;

        $this->assertSame('mailchimp', $attribution['utm_source'] ?? null);
        $this->assertSame('email', $attribution['utm_medium'] ?? null);
        $this->assertSame('email', ChannelClassifier::classify($attribution));
    }

    /** Erasure is not deletion: the money is still on the books. */
    public function test_the_financial_record_survives(): void
    {
        $this->erase();

        $donation = $this->donation();
        $this->assertSame(4000, (int) $donation->amount_cents);
        $this->assertSame('paid', $donation->status);
    }

    /** A donation with nothing but utm keys is left exactly as it was. */
    public function test_attribution_holding_no_personal_data_is_untouched(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference          = 'DONO-ATTR-CLEAN-' . uniqid();
        $d->donor_id           = $this->donorId;
        $d->amount_cents       = 1500;
        $d->base_amount_cents  = 1500;
        $d->currency           = 'USD';
        $d->base_currency      = 'USD';
        $d->status             = 'paid';
        $d->gateway            = 'offline';
        $d->frequency          = 'one_time';
        $d->kind               = 'donation';
        $d->is_test            = false;
        $d->source_attribution = ['utm_source' => 'admin', 'utm_medium' => ChannelClassifier::MANUAL];
        $d->paid_at            = $now;
        $d->created_at         = $now;
        $d->updated_at         = $now;
        $d->save();

        $this->erase();

        $fresh = Donation::query()->find('id', (int) $d->id);
        $this->assertSame(
            ['utm_source' => 'admin', 'utm_medium' => ChannelClassifier::MANUAL],
            (array) $fresh->source_attribution
        );
    }
}
