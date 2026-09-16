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
echo wp_kses_data(get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--stat is-' . $size . ' is-align-' . $align,
    'style' => $styleVars,
])));
?> data-block="gratora/campaign-stat" data-metric="<?php echo esc_attr($metric);
?>">
    <div class="gratora-stat__label"><?php echo esc_html($label);
?></div>
    <div class="gratora-stat__value"><?php echo esc_html($value);
?></div>
</div>
