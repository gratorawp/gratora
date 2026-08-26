<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var string $align
 */
?>
<p class="giveflow-block giveflow-block--paragraph giveflow-paragraph giveflow-paragraph--<?php echo esc_attr((string) $align); ?>">
    <?php echo wp_kses_post((string) $text); ?>
</p>
