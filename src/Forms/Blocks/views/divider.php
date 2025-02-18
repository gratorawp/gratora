<?php
defined('ABSPATH') || exit;
/**
 * @var int    $marginTop
 * @var int    $marginBottom
 * @var int    $thickness
 * @var string $color  hex or '' (inherit --dono-border)
 */
$line = $color !== '' ? $color : 'var(--dono-border, #e5e7eb)';
$style = sprintf(
    'margin:%dpx 0 %dpx;border:0;border-top:%dpx solid %s;width:100%%;',
    (int) $marginTop,
    (int) $marginBottom,
    (int) $thickness,
    $line
);
?>
<hr class="dono-block dono-block--divider dono-divider" style="<?php echo esc_attr($style); ?>">
