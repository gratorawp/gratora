<?php

declare(strict_types=1);

namespace FundKit\Donors\Portal;

use FundKit\Campaigns\Styling\Tokens;
use FundKit\Campaigns\Styling\CampaignStyleResolver;
use FundKit\Donations\Donation;
use FundKit\Donations\DonationQueries;
use FundKit\Donations\Refund;
use FundKit\Donors\Donor;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\Helpers\View;
use FundKit\Receipts\OrgProfile;
use FundKit\Receipts\PdfBuilder;

/** @since 1.0.0 */
final class AnnualStatementBuilder
{
    /** Names this builder to the fundkit.statement.pdf filter. */
    public const KIND = 'portal';

    /** @since 1.0.0 */
    public function __construct(private PdfBuilder $pdf)
    {
    }

    /** @since 1.0.0 */
    public function build(Donor $donor, int $year): string
    {
        // An add-on that issues jurisdiction-correct annual documents replaces
        // this one outright. Without the seam a donor can reach two different
        // annual statements for the same year from the same portal, only one
        // of which satisfies their tax authority.
        $override = apply_filters('fundkit.statement.pdf', null, $donor, $year, self::KIND);
        if (is_string($override) && $override !== '') {
            return $override;
        }

        [$start, $end] = DonationQueries::yearBoundsUtc($year);

        // donationsOnly, matching the admin-side statement: a ticket purchase
        // is goods received, not a donation, and must never be itemized on a
        // document the donor files as deductible.
        $rows = DonationQueries::donationsOnly(Donation::query())
            ->whereIn('status', ['paid', 'partial_refund'])
            ->where('donor_id', $donor->id)
            ->whereBetween('paid_at', $start, $end)
            ->orderBy('paid_at', 'ASC')
            ->getAll();

        if (empty($rows)) return '';

        // Build a per-donation refunded-cents map so the statement can show
        // the net (donor-kept) amount for tax purposes. partial_refund rows
        // would otherwise overstate the deductible amount.
        $refundedByDonation = [];
        $donationIds = array_map(static fn ($d) => (int) $d->id, $rows);
        $refundRows = Refund::query()
            ->whereIn('donation_id', $donationIds)
            ->where('status', 'succeeded')
            ->getAll();
        foreach ($refundRows as $r) {
            $did = (int) $r->donation_id;
            $refundedByDonation[$did] = ($refundedByDonation[$did] ?? 0) + (int) $r->amount_cents;
        }

        $totalsByCurrency = [];
        $lines = [];
        foreach ($rows as $d) {
            $netCents = (int) $d->amount_cents - ($refundedByDonation[(int) $d->id] ?? 0);
            if ($netCents <= 0) continue;
            $cur = (string) $d->currency;
            $totalsByCurrency[$cur] = ($totalsByCurrency[$cur] ?? 0) + $netCents;
            $lines[] = [
                'date'      => (string) wp_date(get_option('date_format'), strtotime((string) $d->paid_at)),
                'reference' => (string) $d->reference,
                'currency'  => $cur,
                'amount'    => Money::format($netCents, $cur),
            ];
        }
        if (empty($totalsByCurrency)) return '';

        // One total per currency: a donor who gave in more than one currency
        // gets correct per-currency sums instead of mixed cents printed under a
        // single symbol. The common single-currency case yields one total line.
        $totals = [];
        foreach ($totalsByCurrency as $cur => $cents) {
            $totals[] = ['currency' => $cur, 'amount' => Money::format($cents, $cur)];
        }

        $org      = OrgProfile::load();
        $orgName  = (string) $org['name'];
        $donorName = trim(($donor->first_name ?? '') . ' ' . ($donor->last_name ?? ''));
        if ($donorName === '') $donorName = __('Friend', 'fundraising-toolkit');

        $html = View::loadRelative(__DIR__, 'views/annual-statement', [
            'accent' => Tokens::printColor((new CampaignStyleResolver())->accentFor(null), '#211d3f'),
            'year'       => $year,
            'org_name'   => $orgName,
            'donor_name' => $donorName,
            'lines'      => $lines,
            'totals'     => $totals,
        ]);

        return $this->pdf->fromHtml($html, [
            'title'   => sprintf(/* translators: %d: year */ __('Annual statement %d', 'fundraising-toolkit'), $year),
            'author'  => $orgName,
            'subject' => __('Annual donation statement', 'fundraising-toolkit'),
        ]);
    }
}
