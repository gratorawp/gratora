<?php
defined('ABSPATH') || exit;
/**
 * @var string $metric
 * @var string $value
 * @var string $label
 * @var string $size
 * @var string $align
 * @var string $styleVars
 */
?>
<div <?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'giveflow-block giveflow-block--stat is-' . $size . ' is-align-' . $align,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="giveflow/campaign-stat" data-metric="<?php echo esc_attr($metric);
?>">
    <div class="giveflow-stat__label"><?php echo esc_html($label);
?></div>
    <div class="giveflow-stat__value"><?php echo esc_html($value);
?></div>
</div>
