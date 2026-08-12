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
// Core escapes these attributes; its own blocks print them the same way.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--stat is-' . $size . ' is-align-' . $align,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="dono/campaign-stat" data-metric="<?php echo esc_attr($metric);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>">
    <div class="dono-stat__label"><?php echo esc_html($label);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></div>
    <div class="dono-stat__value"><?php echo esc_html($value);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></div>
</div>
