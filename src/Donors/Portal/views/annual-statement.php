<?php
defined('ABSPATH') || exit;
/**
 * Annual donation statement, rendered to HTML then handed to the PDF builder.
 * Standalone document with a scoped <style> block (no theme/token context),
 * so the literal colors below are intentional and self-contained.
 *
 * @var int    $year       statement year
 * @var string $org_name   organization name
 * @var string $donor_name donor display name
 * @var array  $lines      list of ['date','reference','currency','amount'] (pre-formatted, raw)
 * @var array  $totals     list of ['currency','amount'] (pre-formatted), one per currency
 */
?>
<html><head><meta charset="utf-8"><style>
body{font-family:Helvetica,Arial,sans-serif;color:#111;font-size:13px;padding:24px}
h1{margin:0 0 4px;font-size:22px}h2{font-size:14px;color:#666;margin:0 0 24px;font-weight:normal}
table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:8px 6px;border-bottom:1px solid #eee;text-align:left}
tfoot td{font-weight:700;border-top:2px solid #111;border-bottom:0}
.total{font-size:18px;margin-top:18px;text-align:right}
</style></head><body>
<h1><?php echo esc_html(sprintf(/* translators: %d: year */ __('Annual donation statement %d', 'dono-fundraising-platform'), $year)); ?></h1>
<h2><?php echo esc_html($org_name); ?></h2>
<p><?php echo esc_html(sprintf(/* translators: %s: donor name */ __('Issued to %s', 'dono-fundraising-platform'), $donor_name)); ?></p>
<table>
<thead><tr>
    <th><?php esc_html_e('Date', 'dono-fundraising-platform'); ?></th>
    <th><?php esc_html_e('Reference', 'dono-fundraising-platform'); ?></th>
    <th><?php esc_html_e('Currency', 'dono-fundraising-platform'); ?></th>
    <th style="text-align:right"><?php esc_html_e('Amount', 'dono-fundraising-platform'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($lines as $line): ?>
    <tr>
        <td><?php echo esc_html($line['date']); ?></td>
        <td><?php echo esc_html($line['reference']); ?></td>
        <td><?php echo esc_html($line['currency']); ?></td>
        <td style="text-align:right"><?php echo esc_html($line['amount']); ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<?php foreach ($totals as $t): ?>
    <tr>
        <td colspan="3" style="text-align:right"><?php
            echo esc_html(count($totals) > 1
                ? sprintf(/* translators: %s: currency code */ __('Total (%s)', 'dono-fundraising-platform'), $t['currency'])
                : __('Total', 'dono-fundraising-platform'));
        ?></td>
        <td style="text-align:right"><?php echo esc_html($t['amount']); ?></td>
    </tr>
<?php endforeach; ?>
</tfoot>
</table>
</body></html>
