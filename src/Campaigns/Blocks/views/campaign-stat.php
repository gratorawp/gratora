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
<div <?php echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--stat is-' . $size . ' is-align-' . $align,
    'style' => $styleVars,
])); ?> data-block="dono/campaign-stat" data-metric="<?php echo esc_attr($metric); ?>">
    <div class="dono-stat__label"><?php echo esc_html($label); ?></div>
    <div class="dono-stat__value"><?php echo esc_html($value); ?></div>
</div>
