<?php
defined('ABSPATH') || exit;
/**
 * @var string $goalType   amount|donations|donors
 * @var int    $current
 * @var int    $target
 * @var int    $pct
 * @var string $currency
 * @var bool   $showLabels
 * @var string $align
 * @var string $styleVars
 */
$formatValue = static function (int $value, string $type, string $currency): string {
    if ($type === 'amount') {
        return \GiveFlow\Foundation\Helpers\Money::compact($value, $currency);
    }
    return (string) number_format_i18n($value);
};
?>
<section <?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'giveflow-block giveflow-block--progress is-align-' . $align,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="giveflow/campaign-progress">
    <?php if ($showLabels): ?>
        <div class="giveflow-progress__labels">
            <div class="giveflow-progress__current">
                <span class="giveflow-progress__value"><?php echo esc_html($formatValue($current, $goalType, $currency));
?></span>
                <span class="giveflow-progress__caption">
                    <?php echo esc_html(match ($goalType) {
                        'donations' => __('donations', 'giveflow-fundraising-campaigns'),
                        'donors'    => __('donors', 'giveflow-fundraising-campaigns'),
                        default     => __('raised', 'giveflow-fundraising-campaigns'),
                    });
?>
                </span>
            </div>
            <?php if ($target > 0): ?>
                <div class="giveflow-progress__target">
                    <?php echo esc_html(sprintf(
                        /* translators: %1$s: percent, %2$s: target value */
                        __('%1$d%% of %2$s goal', 'giveflow-fundraising-campaigns'),
                        $pct,
                        $formatValue($target, $goalType, $currency)
                    ));
?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="giveflow-progress__bar" role="progressbar"
         aria-valuenow="<?php echo esc_attr((string) $pct);
?>"
         aria-valuemin="0" aria-valuemax="100">
        <div class="giveflow-progress__bar-fill" style="width: <?php echo esc_attr((string) $pct);
?>%;"></div>
    </div>
</section>
