<?php
defined('ABSPATH') || exit;
/**
 * Campaign performance one-pager, rendered to HTML then handed to the PDF
 * builder. Standalone document with a scoped <style> block (no theme/token
 * context), so the literal colors below are intentional and self-contained.
 * Aggregate figures only: no donor names or PII appear on this document.
 *
 * @var string $org_name       organization name
 * @var string $campaign_title campaign title
 * @var string $range_label    human-readable reporting window
 * @var string $raised         formatted amount raised (big figure)
 * @var bool   $has_goal       whether a goal is set for this campaign
 * @var string $goal_display   formatted goal (e.g. "$10,000.00" or "100 donations"), '' when none
 * @var int    $percent        progress percent for the label (may exceed 100)
 * @var int    $bar_width      progress width clamped to 0..100 for the CSS bar
 * @var array  $stats          list of ['label','value'] pairs (pre-formatted)
 * @var string $generated_date formatted generation date
 */
?>
<html><head><meta charset="utf-8"><style>
body{font-family:Helvetica,Arial,sans-serif;color:#111;font-size:13px}
.eyebrow{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666;margin:0 0 2px}
h1{margin:0 0 2px;font-size:24px;line-height:1.15}
.range{font-size:12px;color:#666;margin:0 0 26px}
.raised{font-size:34px;font-weight:700;margin:0 0 2px}
.goal{font-size:13px;color:#444;margin:0 0 10px}
.bar{height:10px;background:#eee;border:1px solid #ddd;border-radius:5px;overflow:hidden;margin:0 0 30px}
.bar > span{display:block;height:10px;background:#1e8a4e}
table.stats{width:100%;border-collapse:collapse;border-top:1px solid #111}
table.stats td{padding:14px 6px;border-bottom:1px solid #eee;vertical-align:top;width:33%}
.stat-label{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#666;margin:0 0 4px}
.stat-value{font-size:19px;font-weight:700}
.footer{margin-top:34px;padding-top:10px;border-top:1px solid #eee;font-size:11px;color:#888}
</style></head><body>
<p class="eyebrow"><?php echo esc_html($org_name); ?></p>
<h1><?php echo esc_html($campaign_title); ?></h1>
<p class="range"><?php echo esc_html($range_label); ?></p>

<p class="raised"><?php echo esc_html(sprintf(/* translators: %s: formatted amount raised */ __('%s raised', 'dono'), $raised)); ?></p>
<?php if ($has_goal): ?>
    <p class="goal"><?php echo esc_html(sprintf(
        /* translators: 1: formatted goal, 2: percent complete */
        __('of %1$s goal (%2$d%% complete)', 'dono'),
        $goal_display,
        $percent
    )); ?></p>
    <div class="bar"><span style="width:<?php echo (int) $bar_width; ?>%"></span></div>
<?php else: ?>
    <p class="goal"><?php esc_html_e('No goal set for this campaign.', 'dono'); ?></p>
<?php endif; ?>

<table class="stats"><tr>
<?php foreach ($stats as $stat): ?>
    <td>
        <div class="stat-label"><?php echo esc_html($stat['label']); ?></div>
        <div class="stat-value"><?php echo esc_html($stat['value']); ?></div>
    </td>
<?php endforeach; ?>
</tr></table>

<p class="footer"><?php echo esc_html(sprintf(
    /* translators: 1: generation date, 2: organization name */
    __('Generated %1$s, %2$s', 'dono'),
    $generated_date,
    $org_name
)); ?></p>
</body></html>
