<?php
defined('ABSPATH') || exit;
/**
 * @var int    $marginTop
 * @var int    $marginBottom
 * @var int    $thickness
 * @var string $color  hex or '' (inherit --giveflow-border)
 */
$line = $color !== '' ? $color : 'var(--giveflow-border, #e5e7eb)';
$style = sprintf(
    'margin:%dpx 0 %dpx;border:0;border-top:%dpx solid %s;width:100%%;',
    (int) $marginTop,
    (int) $marginBottom,
    (int) $thickness,
    $line
);
?>
<hr class="giveflow-block giveflow-block--divider giveflow-divider" style="<?php echo esc_attr($style); ?>">
