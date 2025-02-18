<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var string $align
 */
?>
<p class="dono-block dono-block--paragraph dono-paragraph dono-paragraph--<?php echo esc_attr((string) $align); ?>">
    <?php echo wp_kses_post((string) $text); ?>
</p>
