<?php
defined('ABSPATH') || exit;
/**
 * Revenue one-pager, rendered to HTML then handed to the PDF builder.
 * Standalone document with a scoped <style> block (no theme/token context), so
 * the literal colors below are intentional and self-contained. Aggregate
 * figures only: no donor names or PII appear on this document.
 *
 * @var string $org_name       organization name
 * @var string $year           four-digit year
 * @var string $total          formatted year total (big figure)
 * @var array  $months         list of ['label','count','amount'] rows
 * @var array  $stats          list of ['label','value'] pairs (pre-formatted)
 * @var string $generated_date formatted generation date
 */
?>
<html><head><meta charset="utf-8"><style>
body{font-family:Helvetica,Arial,sans-serif;color:#111;font-size:13px}
.eyebrow{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666;margin:0 0 2px}
h1{margin:0 0 2px;font-size:24px;line-height:1.15}
.range{font-size:12px;color:#666;margin:0 0 26px}
.raised{font-size:34px;font-weight:700;margin:0 0 24px}
table.stats{width:100%;border-collapse:collapse;border-top:1px solid #111;margin-bottom:30px}
table.stats td{padding:14px 6px;border-bottom:1px solid #eee;vertical-align:top;width:33%}
.stat-label{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#666;margin:0 0 4px}
.stat-value{font-size:19px;font-weight:700}
table.months{width:100%;border-collapse:collapse}
table.months th{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#666;text-align:left;padding:0 6px 8px;border-bottom:1px solid #111}
table.months td{padding:9px 6px;border-bottom:1px solid #eee}
table.months td.num,table.months th.num{text-align:right}
.footer{margin-top:34px;padding-top:10px;border-top:1px solid #eee;font-size:11px;color:#888}
</style></head><body>
<p class="eyebrow"><?php echo esc_html($org_name); ?></p>
<h1><?php echo esc_html(sprintf(/* translators: %s: four-digit year */ __('Revenue %s', 'dono-fundraising-platform'), $year)); ?></h1>
<p class="range"><?php echo esc_html__('Donations received, by month', 'dono-fundraising-platform'); ?></p>

<p class="raised"><?php echo esc_html(sprintf(/* translators: %s: formatted amount raised */ __('%s raised', 'dono-fundraising-platform'), $total)); ?></p>

<table class="stats"><tr>
<?php foreach ($stats as $stat) : ?>
    <td>
        <p class="stat-label"><?php echo esc_html($stat['label']); ?></p>
        <div class="stat-value"><?php echo esc_html($stat['value']); ?></div>
    </td>
<?php endforeach; ?>
</tr></table>

<table class="months">
    <thead>
        <tr>
            <th><?php echo esc_html__('Month', 'dono-fundraising-platform'); ?></th>
            <th class="num"><?php echo esc_html__('Donations', 'dono-fundraising-platform'); ?></th>
            <th class="num"><?php echo esc_html__('Revenue', 'dono-fundraising-platform'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($months as $month) : ?>
        <tr>
            <td><?php echo esc_html($month['label']); ?></td>
            <td class="num"><?php echo esc_html($month['count']); ?></td>
            <td class="num"><?php echo esc_html($month['amount']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p class="footer"><?php echo esc_html(sprintf(/* translators: %s: formatted date */ __('Generated %s', 'dono-fundraising-platform'), $generated_date)); ?></p>
</body></html>
