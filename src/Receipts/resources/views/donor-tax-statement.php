<?php
defined('ABSPATH') || exit;
/**
 * Donor year-end tax statement (US 501(c)(3) style contribution acknowledgement),
 * rendered to HTML then handed to the PDF builder. Standalone document with a
 * scoped <style> block (no theme/token context), so the literal colors below are
 * intentional and self-contained.
 *
 * LEGALLY SENSITIVE: this is a draft acknowledgement for a human to review
 * before it is issued. The acknowledgement sentence is printed verbatim and is
 * intentionally NOT passed through translation so it stays exact.
 *
 * @var int    $year                statement year
 * @var string $org_name            organization name
 * @var array  $org_address_lines   list of address lines (may be empty)
 * @var string $org_tax_id          tax id / EIN, '' when not configured (EIN line omitted)
 * @var string $donor_name          donor display name
 * @var array  $donor_address_lines list of address lines (may be empty)
 * @var array  $lines               list of ['date','reference','amount','refunded_note']
 * @var array  $totals              list of ['label','amount'] (one per currency)
 * @var string $org_disclaimer      optional org receipt disclaimer to append, '' when none
 * @var string $generated_date      formatted generation date
 */
?>
<html><head><meta charset="utf-8"><style>
body{font-family:'Times New Roman',Times,serif;color:#000;font-size:12.5px;line-height:1.5}
.masthead{border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:18px}
.org-name{font-size:17px;font-weight:700;margin:0 0 3px}
.org-meta{font-size:11.5px;color:#222;margin:0;white-space:pre-line}
.ein{font-size:11.5px;color:#222;margin:4px 0 0}
.parties{margin:0 0 20px}
.party-label{font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:#555;margin:0 0 2px}
.donor-block{font-size:12.5px;margin:0 0 18px;white-space:pre-line}
h1{font-size:18px;margin:0 0 10px}
.intro{margin:0 0 16px}
table{width:100%;border-collapse:collapse;margin:0 0 4px}
th{font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:#555;text-align:left;border-bottom:1px solid #000;padding:6px 6px}
th.amt,td.amt{text-align:right}
td{padding:7px 6px;border-bottom:1px solid #ddd;vertical-align:top}
.ref-note{display:block;font-size:10.5px;color:#666;margin-top:2px}
tfoot td{font-weight:700;border-top:2px solid #000;border-bottom:0;padding-top:9px}
.ack{margin:22px 0 0;padding:12px 14px;border:1px solid #000;font-size:12.5px}
.disclaimer{margin:14px 0 0;font-size:11px;color:#333}
.footer{margin-top:26px;padding-top:9px;border-top:1px solid #ccc;font-size:10.5px;color:#666}
</style></head><body>

<div class="masthead">
    <p class="org-name"><?php echo esc_html($org_name); ?></p>
    <?php if (! empty($org_address_lines)): ?>
        <p class="org-meta"><?php echo esc_html(implode("\n", $org_address_lines)); ?></p>
    <?php endif; ?>
    <?php if ($org_tax_id !== ''): ?>
        <p class="ein"><?php echo esc_html(sprintf(/* translators: %s: tax id / EIN */ __('Tax ID (EIN): %s', 'dono-fundraising-platform'), $org_tax_id)); ?></p>
    <?php endif; ?>
</div>

<div class="parties">
    <p class="party-label"><?php esc_html_e('Issued to', 'dono-fundraising-platform'); ?></p>
    <div class="donor-block"><?php
        $donorBlock = $donor_name;
        if (! empty($donor_address_lines)) {
            $donorBlock .= "\n" . implode("\n", $donor_address_lines);
        }
        echo esc_html($donorBlock);
    ?></div>
</div>

<h1><?php echo esc_html(sprintf(/* translators: %d: statement year */ __('%d Annual Donation Statement', 'dono-fundraising-platform'), $year)); ?></h1>

<p class="intro"><?php echo esc_html(sprintf(
    /* translators: 1: statement year, 2: organization name */
    __('Thank you for your %1$d contributions to %2$s.', 'dono-fundraising-platform'),
    $year,
    $org_name
)); ?></p>

<table>
<thead><tr>
    <th><?php esc_html_e('Date', 'dono-fundraising-platform'); ?></th>
    <th><?php esc_html_e('Reference', 'dono-fundraising-platform'); ?></th>
    <th class="amt"><?php esc_html_e('Amount', 'dono-fundraising-platform'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($lines as $line): ?>
    <tr>
        <td><?php echo esc_html($line['date']); ?></td>
        <td>
            <?php echo esc_html($line['reference']); ?>
            <?php if (! empty($line['refunded_note'])): ?>
                <span class="ref-note"><?php echo esc_html($line['refunded_note']); ?></span>
            <?php endif; ?>
        </td>
        <td class="amt"><?php echo esc_html($line['amount']); ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<?php foreach ($totals as $total): ?>
    <tr>
        <td colspan="2"><?php echo esc_html($total['label']); ?></td>
        <td class="amt"><?php echo esc_html($total['amount']); ?></td>
    </tr>
<?php endforeach; ?>
</tfoot>
</table>

<?php
// Printed verbatim and intentionally not translated so the required 501(c)(3)
// acknowledgement wording stays exact. A real org may need to customize it.
?>
<p class="ack">No goods or services were provided in exchange for these contributions.</p>

<?php if ($org_disclaimer !== ''): ?>
    <p class="disclaimer"><?php echo esc_html($org_disclaimer); ?></p>
<?php endif; ?>

<p class="footer">
    <?php echo esc_html(sprintf(/* translators: %s: generation date */ __('Generated %s.', 'dono-fundraising-platform'), $generated_date)); ?>
    <?php esc_html_e('Retain this statement for your tax records.', 'dono-fundraising-platform'); ?>
</p>
</body></html>
