<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Donors\Portal\AnnualStatementBuilder;
use Dono\Receipts\PdfBuilder;
use Dono\Receipts\ReceiptIssuer;
use Dono\Reports\TaxStatementBuilder;

/**
 * The three seams an add-on issuing jurisdiction-correct documents needs.
 *
 * Each defaults to today's behaviour exactly, which is the property worth
 * testing: a filter that quietly changes what core does for everybody who does
 * not use it is worse than no filter at all.
 */
final class ReceiptSeamsTest extends IntegrationTestCase
{
    // -- dono.statement.pdf --------------------------------------------------

    /**
     * Both builders, because the portal route calls one and the admin route
     * and the tax_statement command call the other. An add-on that replaced
     * only one would leave a donor able to download two different annual
     * documents for the same year.
     */
    public function test_both_annual_statement_builders_can_be_overridden(): void
    {
        $donor = $this->makeDonor();
        $seen  = [];

        add_filter('dono.statement.pdf', static function ($pdf, $d, $year, $kind) use (&$seen) {
            $seen[] = $kind;
            return 'PDF-' . $kind;
        }, 10, 4);

        $this->assertSame('PDF-portal', (new AnnualStatementBuilder(new PdfBuilder()))->build($donor, 2026));
        $this->assertSame('PDF-tax', $this->taxBuilder()->build($donor, 2026));
        $this->assertSame(['portal', 'tax'], $seen, 'Each builder names itself so an add-on can tell them apart.');
    }

    public function test_the_filter_receives_the_donor_and_the_year(): void
    {
        $donor = $this->makeDonor();
        $got   = null;

        add_filter('dono.statement.pdf', static function ($pdf, $d, $year) use (&$got) {
            $got = ['donor_id' => (int) $d->id, 'year' => $year];
            return 'PDF';
        }, 10, 4);

        (new AnnualStatementBuilder(new PdfBuilder()))->build($donor, 2024);

        $this->assertSame(['donor_id' => (int) $donor->id, 'year' => 2024], $got);
    }

    /** Returning anything that is not a non-empty string falls through. */
    public function test_a_filter_that_declines_leaves_core_in_charge(): void
    {
        $donor = $this->makeDonor();

        foreach ([null, '', false, 123, []] as $decline) {
            add_filter('dono.statement.pdf', static fn () => $decline, 10, 4);
            // No donations, so core's own answer is the empty string. The point
            // is that it got as far as core rather than returning the value.
            $this->assertSame('', (new AnnualStatementBuilder(new PdfBuilder()))->build($donor, 2026));
            remove_all_filters('dono.statement.pdf');
        }
    }

    public function test_with_nothing_hooked_the_builders_behave_as_before(): void
    {
        $donor = $this->makeDonor();

        $this->assertSame('', (new AnnualStatementBuilder(new PdfBuilder()))->build($donor, 2026));
        $this->assertSame('', $this->taxBuilder()->build($donor, 2026));
    }

    // -- dono.receipt.should_issue -------------------------------------------

    /**
     * The default is unchanged: a donation is receipted, a ticket order is not.
     * "No receipt" is the safe answer rather than the right one, which is why
     * the filter exists.
     */
    public function test_the_issuance_default_is_unchanged(): void
    {
        $this->assertTrue($this->wouldIssue($this->makeDonation('donation')));
        $this->assertFalse($this->wouldIssue($this->makeDonation('order')));
    }

    public function test_an_add_on_can_turn_issuance_on_for_its_own_kind(): void
    {
        add_filter(
            'dono.receipt.should_issue',
            static fn (bool $should, $donation): bool => $should || (string) $donation->kind === 'order',
            10,
            2
        );

        $this->assertTrue($this->wouldIssue($this->makeDonation('order')));
    }

    public function test_an_add_on_can_also_turn_issuance_off(): void
    {
        add_filter('dono.receipt.should_issue', static fn (): bool => false, 10, 2);

        $this->assertFalse($this->wouldIssue($this->makeDonation('donation')));
    }

    public function test_the_filter_receives_the_donation(): void
    {
        $donation = $this->makeDonation('order');
        $got      = null;

        add_filter('dono.receipt.should_issue', static function (bool $should, $d) use (&$got): bool {
            $got = (string) $d->reference;
            return $should;
        }, 10, 2);

        $this->wouldIssue($donation);

        $this->assertSame((string) $donation->reference, $got);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Whether onDonationCompleted would queue an issue job.
     *
     * Reads the async queue rather than reaching into the gate, so the test
     * survives the method being refactored.
     */
    private function wouldIssue(Donation $donation): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_actions';

        // The hook name is private, so read the real one rather than copying
        // the string: a copy would keep passing if core renamed it.
        $hook = (string) (new \ReflectionClass(ReceiptIssuer::class))->getConstant('HOOK');

        $count = static fn (): int => (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE hook = %s", $hook)
        );

        $before = $count();
        do_action('dono.donation.completed', $donation);

        return $count() > $before;
    }

    private function taxBuilder(): TaxStatementBuilder
    {
        return \Dono\Foundation\Plugin::instance()->container->get(TaxStatementBuilder::class);
    }

    private function makeDonor(): Donor
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donor::make();
        $d->email_hash      = hash('sha256', uniqid('seam', true));
        $d->email_encrypted = 'encrypted';
        $d->created_at      = $now;
        $d->updated_at      = $now;
        $d->save();

        return $d;
    }

    private function makeDonation(string $kind): Donation
    {
        $now = gmdate('Y-m-d H:i:s');
        $d = Donation::make();
        $d->reference    = 'SEAM-' . strtoupper(uniqid());
        $d->donor_id     = (int) $this->makeDonor()->id;
        $d->amount_cents = 5000;
        $d->net_cents    = 5000;
        $d->currency     = 'USD';
        $d->status       = 'paid';
        $d->gateway      = 'offline';
        $d->kind         = $kind;
        $d->paid_at      = $now;
        $d->created_at   = $now;
        $d->updated_at   = $now;
        $d->save();

        return $d;
    }
}
