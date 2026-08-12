<?php

defined('ABSPATH') || exit;

use Dono\Donations\Donation;
use Dono\Donors\Donor;

/**
 * @var Donation $donation
 * @var Donor    $donor
 * @var string   $donor_name      name given for this donation (resolved)
 * @var string   $donor_address   formatted multi-line address, empty when missing
 * @var array    $org             keys: name, address_lines (array), tax_id, email
 * @var string   $locale
 * @var array    $extras
 * @var string   $amount_display  e.g. "50,00 EUR"
 * @var string   $receipt_number
 * @var array    $receipt_template keys: header_title, intro, signoff, footer_note,
 *                                  show_tax_id, show_donor_address, logo_url, accent_color
 * @var int      $refunded_cents
 * @var string   $refunded_display empty when no refund
 * @var array    $custom_data
 * @var array    $custom_field_labels
 */

$orgName = (string) ($org['name'] ?? __('Your Organization', 'dono-fundraising-platform'));
$orgAddressLines = (array) ($org['address_lines'] ?? []);
$orgTaxId  = (string) ($org['tax_id'] ?? '');
$orgEmail  = (string) ($org['email'] ?? '');

$tpl = is_array($receipt_template ?? null) ? $receipt_template : [];
$headerTitle = (string) ($tpl['header_title'] ?? __('Donation receipt', 'dono-fundraising-platform'));
$intro       = (string) ($tpl['intro']        ?? '');
$signoff     = (string) ($tpl['signoff']      ?? __('Thank you for your support.', 'dono-fundraising-platform'));
$footerNote  = (string) ($tpl['footer_note']  ?? '');
$showTaxId   = array_key_exists('show_tax_id', $tpl) ? (bool) $tpl['show_tax_id'] : true;
$showDonorAddr = array_key_exists('show_donor_address', $tpl) ? (bool) $tpl['show_donor_address'] : false;
$logoUrl     = (string) ($tpl['logo_url']     ?? '');
$accent      = (string) ($tpl['accent_color'] ?? '#1e8a4e');

$donorName = trim((string) ($donor_name ?? ''));
if ($donorName === '') $donorName = '-';

// The whole receipt renders in the donor's locale, so the frequency has to be
// a translated label rather than the stored slug.
$frequencyLabels = [
    'weekly'    => __('Weekly', 'dono-fundraising-platform'),
    'biweekly'  => __('Every 2 weeks', 'dono-fundraising-platform'),
    'monthly'   => __('Monthly', 'dono-fundraising-platform'),
    'quarterly' => __('Quarterly', 'dono-fundraising-platform'),
    'yearly'    => __('Yearly', 'dono-fundraising-platform'),
];
$frequencyLabel = $donation->frequency === 'one_time'
    ? __('One-time donation', 'dono-fundraising-platform')
    : sprintf(
        /* translators: %s: frequency label (Monthly, Quarterly, Yearly, …). */
        __('Recurring donation (%s)', 'dono-fundraising-platform'),
        $frequencyLabels[(string) $donation->frequency] ?? ucfirst((string) $donation->frequency)
    );

$paidAt = $donation->paid_at
    ? wp_date(get_option('date_format') . ', H:i', strtotime($donation->paid_at))
    : '-';

$customDataArr   = is_array($custom_data ?? null) ? $custom_data : [];
$customLabelsArr = is_array($custom_field_labels ?? null) ? $custom_field_labels : [];
$customRows      = [];
foreach ($customDataArr as $key => $value) {
    if ($value === null || $value === '') continue;
    $label = (string) ($customLabelsArr[$key] ?? $key);
    if ($label === '') continue;

    if (is_bool($value)) {
        $display = $value ? __('Yes', 'dono-fundraising-platform') : __('No', 'dono-fundraising-platform');
    } elseif (is_array($value)) {
        $display = implode(', ', array_map('strval', $value));
        if ($display === '') continue;
    } else {
        $display = (string) $value;
    }
    $customRows[] = ['label' => $label, 'value' => $display];
}

$donorAddress    = (string) ($donor_address ?? '');
$refundedDisplay = (string) ($refunded_display ?? '');
$receiptNumber   = (string) ($receipt_number ?? '');
$refundedCents   = (int) ($refunded_cents ?? 0);
$netDisplay      = $refundedCents > 0
    ? \Dono\Foundation\Helpers\Money::format(
        (int) $donation->amount_cents - $refundedCents,
        (string) $donation->currency
    )
    : $amount_display;
?>
<!doctype html>
<html lang="<?php echo esc_attr($locale ?: 'en'); ?>">
<head>
    <meta charset="utf-8">
    <style>
        body  { font-family: dejavusans, sans-serif; color: #222; font-size: 11pt; }
        .logo { margin: 0 0 16pt; }
        .logo img { max-height: 56pt; }
        .org  { text-align: right; color: #555; font-size: 9pt; line-height: 1.5; }
        .org strong { color: #222; font-size: 11pt; }
        h1    { font-size: 22pt; font-weight: 300; margin: 28pt 0 8pt; letter-spacing: .5pt; color: <?php echo esc_attr($accent); ?>; }
        .lede { color: #777; margin: 0 0 8pt; font-size: 10pt; }
        .intro { color: #444; margin: 4pt 0 22pt; font-size: 10pt; line-height: 1.55; }
        .ref  { background:#f5f3ef; padding:14pt 16pt; border-radius:4pt; margin:0 0 22pt; border-left: 3pt solid <?php echo esc_attr($accent); ?>; }
        .ref dt { color:#777; font-size:8.5pt; text-transform:uppercase; letter-spacing:.6pt; }
        .ref dd { margin:2pt 0 10pt; font-size:11pt; }
        .ref dd:last-child { margin-bottom: 0; }
        .ref .addr-line { display: block; }
        table.lines { width: 100%; border-collapse: collapse; margin: 6pt 0 20pt; }
        table.lines th, table.lines td { text-align: left; padding: 7pt 6pt; border-bottom: 1pt solid #e7e3dd; font-size: 10.5pt; }
        table.lines th { color:#777; font-weight: 500; font-size: 9pt; text-transform: uppercase; letter-spacing: .4pt; }
        table.lines td.amt { text-align: right; white-space: nowrap; }
        .refund-row td { color: #b91c1c; }
        .total { font-size: 18pt; font-weight: 400; text-align: right; padding-top: 10pt; border-top: 2pt solid #222; }
        .custom { margin: 4pt 0 22pt; }
        .custom h3 { font-size: 9pt; font-weight: 500; color: #777; text-transform: uppercase; letter-spacing: .4pt; margin: 0 0 6pt; }
        .custom dl { margin: 0; }
        .custom dt { color: #555; font-size: 9pt; margin-top: 4pt; }
        .custom dd { margin: 0 0 6pt; font-size: 10.5pt; }
        .note  { color: #777; font-size: 9pt; margin-top: 30pt; line-height: 1.55; }
        .signoff { margin-top: 30pt; font-size: 10pt; color: #444; }
    </style>
</head>
<body>

<?php if (! empty($donation->is_test)): ?>
<div style="border:2pt solid #b91c1c; color:#b91c1c; font-weight:700; text-align:center; padding:8pt; margin:0 0 18pt; letter-spacing:.5pt;">
    <?php esc_html_e('TEST DONATION - NOT A REAL PAYMENT', 'dono-fundraising-platform'); ?>
</div>
<?php endif; ?>

<?php if ($refundedDisplay !== ''): ?>
<div style="border:2pt solid #b91c1c; color:#b91c1c; font-weight:600; padding:8pt 10pt; margin:0 0 18pt;">
    <?php
    printf(
        /* translators: %s: refunded amount. */
        esc_html__('This donation has been refunded (%s).', 'dono-fundraising-platform'),
        esc_html($refundedDisplay)
    );
    ?>
</div>
<?php endif; ?>

<?php if ($logoUrl !== ''): ?>
<div class="logo">
    <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php echo esc_attr($orgName); ?>">
</div>
<?php endif; ?>

<div class="org">
    <strong><?php echo esc_html($orgName); ?></strong><br>
    <?php foreach ($orgAddressLines as $line): ?>
        <?php echo esc_html((string) $line); ?><br>
    <?php endforeach; ?>
    <?php if ($orgEmail !== ''): ?>
        <?php echo esc_html($orgEmail); ?>
    <?php endif; ?>
</div>

<h1><?php echo esc_html($headerTitle); ?></h1>
<p class="lede"><?php echo esc_html($frequencyLabel); ?></p>
<?php if ($intro !== ''): ?>
<p class="intro"><?php echo nl2br(esc_html($intro)); ?></p>
<?php endif; ?>

<dl class="ref">
    <?php if ($receiptNumber !== ''): ?>
        <dt><?php esc_html_e('Receipt number', 'dono-fundraising-platform'); ?></dt>
        <dd><?php echo esc_html($receiptNumber); ?></dd>
    <?php endif; ?>

    <dt><?php esc_html_e('Reference', 'dono-fundraising-platform'); ?></dt>
    <dd><?php echo esc_html($donation->reference); ?></dd>

    <dt><?php esc_html_e('Date', 'dono-fundraising-platform'); ?></dt>
    <dd><?php echo esc_html($paidAt); ?></dd>

    <dt><?php esc_html_e('Donor', 'dono-fundraising-platform'); ?></dt>
    <dd><?php echo esc_html($donorName); ?></dd>

    <?php if ($showDonorAddr && $donorAddress !== ''): ?>
        <dt><?php esc_html_e('Donor address', 'dono-fundraising-platform'); ?></dt>
        <dd>
            <?php foreach (preg_split('/\R/', $donorAddress) as $line): ?>
                <?php $line = trim((string) $line); if ($line === '') continue; ?>
                <span class="addr-line"><?php echo esc_html($line); ?></span>
            <?php endforeach; ?>
        </dd>
    <?php endif; ?>

    <?php if ($showTaxId && $orgTaxId !== ''): ?>
        <dt><?php esc_html_e('Organization tax ID', 'dono-fundraising-platform'); ?></dt>
        <dd><?php echo esc_html($orgTaxId); ?></dd>
    <?php endif; ?>
</dl>

<table class="lines">
    <thead>
        <tr>
            <th><?php esc_html_e('Description', 'dono-fundraising-platform'); ?></th>
            <th class="amt"><?php esc_html_e('Amount', 'dono-fundraising-platform'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?php /* translators: %s: organization name. */ printf(esc_html__('Donation to %s', 'dono-fundraising-platform'), esc_html($orgName)); ?></td>
            <td class="amt"><?php echo esc_html($amount_display); ?></td>
        </tr>
        <?php if ($refundedDisplay !== ''): ?>
        <tr class="refund-row">
            <td><?php esc_html_e('Refunded', 'dono-fundraising-platform'); ?></td>
            <td class="amt">-<?php echo esc_html($refundedDisplay); ?></td>
        </tr>
        <?php endif; ?>
    </tbody>
    <tfoot>
        <tr><td>&nbsp;</td><td class="amt total"><?php echo esc_html($netDisplay); ?></td></tr>
    </tfoot>
</table>

<?php if (! empty($customRows)): ?>
<div class="custom">
    <h3><?php esc_html_e('Additional information', 'dono-fundraising-platform'); ?></h3>
    <dl>
        <?php foreach ($customRows as $row): ?>
            <dt><?php echo esc_html($row['label']); ?></dt>
            <dd><?php echo esc_html($row['value']); ?></dd>
        <?php endforeach; ?>
    </dl>
</div>
<?php endif; ?>

<p class="signoff"><?php echo nl2br(esc_html($signoff)); ?></p>

<?php if ($footerNote !== ''): ?>
<p class="note">
    <?php echo nl2br(esc_html($footerNote)); ?>
</p>
<?php endif; ?>

</body>
</html>
