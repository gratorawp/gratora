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
        return \Gratora\Foundation\Helpers\Money::compact($value, $currency);
    }
    return (string) number_format_i18n($value);
};
?>
<section <?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--progress is-align-' . $align,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="gratora/campaign-progress">
    <?php if ($showLabels): ?>
        <div class="gratora-progress__labels">
            <div class="gratora-progress__current">
                <span class="gratora-progress__value"><?php echo esc_html($formatValue($current, $goalType, $currency));
?></span>
                <span class="gratora-progress__caption">
                    <?php echo esc_html(match ($goalType) {
                        'donations' => __('donations', 'gratora'),
                        'donors'    => __('donors', 'gratora'),
                        default     => __('raised', 'gratora'),
                    });
?>
                </span>
            </div>
            <?php if ($target > 0): ?>
                <div class="gratora-progress__target">
                    <?php echo esc_html(sprintf(
                        /* translators: %1$s: percent, %2$s: target value */
                        __('%1$d%% of %2$s goal', 'gratora'),
                        $pct,
                        $formatValue($target, $goalType, $currency)
                    ));
?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="gratora-progress__bar" role="progressbar"
         aria-valuenow="<?php echo esc_attr((string) $pct);
?>"
         aria-valuemin="0" aria-valuemax="100">
        <div class="gratora-progress__bar-fill" style="width: <?php echo esc_attr((string) $pct);
?>%;"></div>
    </div>
</section>
