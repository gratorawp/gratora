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
    'class' => 'dono-block dono-block--stat is-' . $size . ' is-align-' . $align,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="dono/campaign-stat" data-metric="<?php echo esc_attr($metric);
?>">
    <div class="dono-stat__label"><?php echo esc_html($label);
?></div>
    <div class="dono-stat__value"><?php echo esc_html($value);
?></div>
</div>
