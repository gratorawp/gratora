<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var string $align
 */
?>
<p class="fundkit-block fundkit-block--paragraph fundkit-paragraph fundkit-paragraph--<?php echo esc_attr((string) $align); ?>">
    <?php echo wp_kses_post((string) $text); ?>
</p>
