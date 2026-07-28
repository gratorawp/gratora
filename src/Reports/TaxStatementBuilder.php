<?php

declare(strict_types=1);

namespace Dono\Reports;

use Dono\Donations\DonationRepository;
use Dono\Donors\Donor;
use Dono\Donors\DonorService;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Helpers\View;
use Dono\Receipts\PdfBuilder;

/**
 * Builds a donor year-end tax statement PDF (US 501(c)(3) style contribution
 * acknowledgement) and the matching count/total the command reports without
 * opening the PDF.
 *
 * The deductible figure is net of refunds: a refunded donation is not
 * deductible, so a fully refunded donation is dropped and a partial refund is
 * netted.
 *
 * @version 1.0.0
 */
final class TaxStatementBuilder
{
    /** Names this builder to the dono.statement.pdf filter. */
    public const KIND = 'tax';

    public function __construct(
        private PdfBuilder $pdf,
        private DonationRepository $donations,
        private DonorService $donors,
    ) {
    }

    public function build(Donor $donor, int $year): string
    {
        // See AnnualStatementBuilder: an add-on replacing annual documents
        // has to replace both, or the admin route and the portal route
        // hand out different statements for the same year.
        $override = apply_filters('dono.statement.pdf', null, $donor, $year, self::KIND);
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $itemized = $this->itemize($this->donations->paidForDonorInYear((int) $donor->id, $year));
        if ($itemized['donation_count'] === 0) {
            return '';
        }

        $org        = get_option('dono_org_profile', []);
        $org        = is_array($org) ? $org : [];
        $orgName    = trim((string) ($org['name'] ?? '')) ?: (string) get_bloginfo('name');
        $donorName  = trim(((string) ($donor->first_name ?? '')) . ' ' . ((string) ($donor->last_name ?? '')));
        $donorAddr  = $this->donors->decryptAddress($donor);

        $html = View::load('Receipts.donor-tax-statement', [
            'year'                => $year,
            'org_name'            => $orgName,
            'org_address_lines'   => $this->orgAddressLines($org),
            'org_tax_id'          => trim((string) ($org['tax_id'] ?? '')),
            'donor_name'          => $donorName !== '' ? $donorName : __('Donor', 'dono'),
            'donor_address_lines' => $donorAddr !== null ? explode("\n", $donorAddr) : [],
            'lines'               => $itemized['lines'],
            'totals'              => $itemized['totals'],
            'org_disclaimer'      => $this->orgDisclaimer(),
            'generated_date'      => (string) wp_date(get_option('date_format')),
        ]);

        return $this->pdf->fromHtml($html, [
            /* translators: %d: statement year. */
            'title'   => sprintf(__('%d annual donation statement', 'dono'), $year),
            'author'  => $orgName,
            'subject' => __('Annual donation statement', 'dono'),
            'format'  => 'Letter',
        ]);
    }

    /**
     * Count and net deductible total for the year, computed from the same rows
     * the PDF uses so the command's reported figures match the document.
     *
     * @return array{donation_count:int,total_cents:int,currency:string}
     */
    public function summary(int $donorId, int $year): array
    {
        $itemized = $this->itemize($this->donations->paidForDonorInYear($donorId, $year));
        return [
            'donation_count' => $itemized['donation_count'],
            'total_cents'    => $itemized['total_cents'],
            'currency'       => $itemized['currency'],
        ];
    }

    /** Stable download filename shared by the command link and the streaming route. */
    public static function filename(int $donorId, int $year): string
    {
        return sprintf('dono-tax-statement-%d-donor-%d.pdf', $year, $donorId);
    }

    /**
     * Net each donation (gross minus succeeded refunds), drop fully refunded
     * gifts, and build display lines plus per-currency totals. total_cents is the
     * net sum in minor units; for a single-currency donor (the common case) it is
     * exact. currency is that single currency, else the org default.
     *
     * @param list<array{date:string,amount_cents:int,refunded_cents:int,currency:string,reference:string,receipt_number:?string}> $rows
     * @return array{lines:list<array<string,string>>,totals:list<array{label:string,amount:string}>,donation_count:int,total_cents:int,currency:string}
     */
    private function itemize(array $rows): array
    {
        $lines            = [];
        $totalsByCurrency = [];
        $count            = 0;
        $totalCents       = 0;

        foreach ($rows as $row) {
            $refunded = (int) $row['refunded_cents'];
            $net      = (int) $row['amount_cents'] - $refunded;
            if ($net <= 0) {
                continue;
            }
            $currency = (string) $row['currency'];
            $count++;
            $totalCents                 += $net;
            $totalsByCurrency[$currency] = ($totalsByCurrency[$currency] ?? 0) + $net;

            $lines[] = [
                'date'          => (string) wp_date(get_option('date_format'), strtotime((string) $row['date'])),
                'reference'     => (string) $row['reference'],
                'amount'        => Money::format($net, $currency),
                'refunded_note' => $refunded > 0
                    /* translators: %s: formatted refunded amount */
                    ? sprintf(__('Net of %s refunded', 'dono'), Money::format($refunded, $currency))
                    : '',
            ];
        }

        $multi  = count($totalsByCurrency) > 1;
        $totals = [];
        foreach ($totalsByCurrency as $currency => $cents) {
            $totals[] = [
                'label'  => $multi
                    /* translators: %s: currency code */
                    ? sprintf(__('Total contributions (%s)', 'dono'), $currency)
                    : __('Total contributions', 'dono'),
                'amount' => Money::format($cents, $currency),
            ];
        }

        $currency = count($totalsByCurrency) === 1
            ? (string) array_key_first($totalsByCurrency)
            : Money::defaultCurrency();

        return [
            'lines'          => $lines,
            'totals'         => $totals,
            'donation_count' => $count,
            'total_cents'    => $totalCents,
            'currency'       => $currency,
        ];
    }

    /**
     * Org address as display lines. Prefers the canonical multi-line form; falls
     * back to the structured onboarding fields.
     *
     * @param array<string,mixed> $org
     * @return list<string>
     */
    private function orgAddressLines(array $org): array
    {
        $canonical = $org['address_lines'] ?? null;
        if (is_array($canonical)) {
            $clean = array_values(array_filter(
                array_map(static fn ($l): string => trim((string) $l), $canonical),
                static fn (string $l): bool => $l !== '',
            ));
            if ($clean !== []) {
                return $clean;
            }
        }

        $out = [];
        foreach (['address_line1', 'address_line2'] as $key) {
            $v = trim((string) ($org[$key] ?? ''));
            if ($v !== '') {
                $out[] = $v;
            }
        }
        $city     = trim((string) ($org['city'] ?? ''));
        $region   = trim(trim((string) ($org['state'] ?? '')) . ' ' . trim((string) ($org['postal_code'] ?? '')));
        $cityLine = $city !== '' && $region !== '' ? $city . ', ' . $region : trim($city . $region);
        if ($cityLine !== '') {
            $out[] = $cityLine;
        }
        $country = trim((string) ($org['country'] ?? ''));
        if ($country !== '') {
            $out[] = $country;
        }
        return $out;
    }

    /**
     * Optional org receipt disclaimer to append. Read from the raw stored option
     * (not the merged default) so only a footer the org actually configured is
     * appended to the statement.
     */
    private function orgDisclaimer(): string
    {
        $stored = get_option('dono_receipt_settings', []);
        if (! is_array($stored)) {
            return '';
        }
        return trim((string) ($stored['footer_note'] ?? ''));
    }
}
