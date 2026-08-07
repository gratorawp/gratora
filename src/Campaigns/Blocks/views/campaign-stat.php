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
<div class="dono-block dono-block--stat is-<?php echo esc_attr($size); ?> is-align-<?php echo esc_attr($align); ?>" data-block="dono/campaign-stat" data-metric="<?php echo esc_attr($metric); ?>"<?php echo $styleVars !== '' ? ' style="' . esc_attr($styleVars) . '"' : ''; ?>>
    <div class="dono-stat__label"><?php echo esc_html($label); ?></div>
    <div class="dono-stat__value"><?php echo esc_html($value); ?></div>
</div>
