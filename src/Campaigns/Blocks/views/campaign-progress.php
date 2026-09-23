<?php
defined('ABSPATH') || exit;
/**
 * @var string $goalType   amount|donations|donors
 * @var int    $current
 * @var int    $target
 * @var int    $pct        true progress, which may exceed 100
 * @var int    $barWidth   $pct clamped to the track
 * @var string $currency
 * @var bool   $showLabels
 * @var string $align
 * @var string $styleVars
 */
$formatValue = static function (int $value, string $type, string $currency): string {
    if ($type === 'amount') {
        return \Gratora\Foundation\Helpers\Money::compact($value, $currency);
    }
    return (string) number_format_i18n($value);
};
?>
<section <?php
echo wp_kses_data(get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--progress is-align-' . $align,
    'style' => $styleVars,
])));
?> data-block="gratora/campaign-progress">
    <?php if ($showLabels): ?>
        <div class="gratora-progress__labels">
            <div class="gratora-progress__current">
                <span class="gratora-progress__value"><?php echo esc_html($formatValue($current, $goalType, $currency));
?></span>
                <span class="gratora-progress__caption">
                    <?php echo esc_html(match ($goalType) {
                        'donations' => __('donations', 'gratora-donation-platform'),
                        'donors'    => __('donors', 'gratora-donation-platform'),
                        default     => __('raised', 'gratora-donation-platform'),
                    });
?>
                </span>
            </div>
            <?php if ($target > 0): ?>
                <div class="gratora-progress__target">
                    <?php echo esc_html(sprintf(
                        /* translators: %1$s: percent, %2$s: target value */
                        __('%1$d%% of %2$s goal', 'gratora-donation-platform'),
                        $pct,
                        $formatValue($target, $goalType, $currency)
                    ));
?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="gratora-progress__bar" role="progressbar"
         aria-valuenow="<?php echo esc_attr((string) $barWidth);
?>"
         aria-valuemin="0" aria-valuemax="100">
        <div class="gratora-progress__bar-fill" style="width: <?php echo esc_attr((string) $barWidth);
?>%;"></div>
    </div>
</section>
