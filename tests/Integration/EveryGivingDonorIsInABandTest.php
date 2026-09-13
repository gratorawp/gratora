<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Donors\DonorType;
use Gratora\Exports\DonorExporter;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Plugin;

/**
 * The donors page says how many gave, then bands them by how recently.
 *
 * The headline counted anyone with a donation row; the bands key on the date
 * of a counted donation. A donor whose only donation was charged back
 * satisfied the first and none of the second, so they held a place in the
 * total and appeared in no band under it, and the four shares described a
 * population one larger than they covered.
 */
final class EveryGivingDonorIsInABandTest extends IntegrationTestCase
{
    private function donor(): Donor
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donor::make();
        $d->email_hash      = hash('sha256', uniqid('band', true));
        $d->email_encrypted = Plugin::instance()->container->get(Crypto::class)->encrypt(uniqid() . '@example.test');
        $d->first_name      = 'Ada';
        $d->last_name       = 'Lovelace';
        $d->created_at      = $now;
        $d->updated_at      = $now;
        $d->save();

        return $d;
    }

    private function donation(Donor $donor, string $status, string $paidAt = ''): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $x = Donation::make();
        $x->reference    = 'DON-' . strtoupper(bin2hex(random_bytes(4)));
        $x->donor_id     = (int) $donor->id;
        $x->kind         = 'donation';
        $x->status       = $status;
        $x->amount_cents = 2500;
        $x->currency     = 'USD';
        $x->paid_at      = $paidAt !== '' ? $paidAt : null;
        $x->created_at   = $now;
        $x->updated_at   = $now;
        $x->save();
    }

    /** @return array<string,mixed> */
    private function kpi(): array
    {
        return Plugin::instance()->container->get(DonorMetricsService::class)->insights()['kpi'];
    }

    private function banded(array $kpi): int
    {
        return (int) $kpi['active'] + (int) $kpi['at_risk'] + (int) $kpi['lapsed'] + (int) $kpi['lost'];
    }

    public function test_a_donor_whose_money_was_taken_back_is_not_among_the_giving(): void
    {
        $donor = $this->donor();
        $this->donation($donor, 'disputed');

        $this->assertSame(0, (int) $this->kpi()['total']);
    }

    /** @dataProvider gaveNothing */
    public function test_nor_is_one_whose_only_donation_never_landed(string $status): void
    {
        $donor = $this->donor();
        $this->donation($donor, $status);

        $this->assertSame(0, (int) $this->kpi()['total']);
    }

    /** @return array<string, array{0:string}> */
    public static function gaveNothing(): array
    {
        return [
            'charged back'  => ['disputed'],
            'never cleared' => ['failed'],
            'still moving'  => ['pending'],
        ];
    }

    /** The reason the subquery exists: the counter does not count it. */
    public function test_a_donation_the_counter_missed_still_counts_as_giving(): void
    {
        $donor = $this->donor();
        $this->donation($donor, 'paid', gmdate('Y-m-d H:i:s'));

        // The counter is only synced on its own path, so this stands in for a
        // live donation the donor row does not know about yet.
        Donor::query()->where('id', (int) $donor->id)->update(['donations_count' => 0]);

        $this->assertSame(1, (int) $this->kpi()['total']);
    }

    public function test_the_bands_cover_everyone_the_headline_counts(): void
    {
        $paid = $this->donor();
        $this->donation($paid, 'paid', gmdate('Y-m-d H:i:s'));
        Plugin::instance()->container->get(DonorRepository::class);
        Donor::query()->where('id', (int) $paid->id)
            ->update(['donations_count' => 1, 'total_donated_cents' => 2500, 'last_donation_at' => gmdate('Y-m-d H:i:s')]);

        $charged = $this->donor();
        $this->donation($charged, 'disputed');

        $kpi = $this->kpi();

        $this->assertSame((int) $kpi['total'], $this->banded($kpi));
    }

    /**
     * A strip of cards claiming 101% of the donors claims more than all of
     * them. Three equal bands is the case that shows it: a third rounds to 33
     * however it is rounded, and three of those is 99.
     */
    public function test_the_shares_add_up_to_a_whole(): void
    {
        foreach ([0, 120, 260] as $daysAgo) {
            $d = $this->donor();
            $when = gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400));
            $this->donation($d, 'paid', $when);
            Donor::query()->where('id', (int) $d->id)
                ->update(['donations_count' => 1, 'total_donated_cents' => 2500, 'last_donation_at' => $when]);
        }

        $kpi = $this->kpi();
        $sum = (int) $kpi['active_pct'] + (int) $kpi['at_risk_pct'] + (int) $kpi['lapsed_pct'] + (int) $kpi['lost_pct'];

        $this->assertSame(100, $sum);
    }

    /** Nobody at all is not 100% of nothing. */
    public function test_an_empty_site_claims_no_shares(): void
    {
        $kpi = $this->kpi();

        $this->assertSame(0, (int) $kpi['active_pct'] + (int) $kpi['at_risk_pct'] + (int) $kpi['lapsed_pct'] + (int) $kpi['lost_pct']);
    }

    /** The CSV an owner hands their finance team says the word too. */
    public function test_the_export_names_the_type_in_words(): void
    {
        $donor = $this->donor();
        Donor::query()->where('id', (int) $donor->id)->update(['donor_type' => 'organization']);

        $csv = (new DonorExporter(Plugin::instance()->container->get(DonorService::class)))->toCsv(['columns' => ['first_name', 'donor_type']]);

        $this->assertStringContainsString(DonorType::label('organization'), $csv);
        $this->assertStringNotContainsString('organization', $csv, 'the key is not what a reader wants');
    }
}
