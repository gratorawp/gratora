<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var string $align
 */
?>
<p class="gratora-block gratora-block--paragraph gratora-paragraph gratora-paragraph--<?php echo esc_attr((string) $align); ?>">
    <?php echo wp_kses_post((string) $text); ?>
</p>
