<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var int    $level
 * @var string $align
 */
$tag = 'h' . max(1, min(6, (int) $level));
?>
<<?php echo esc_attr($tag); ?> class="giveflow-block giveflow-block--heading giveflow-heading giveflow-heading--<?php echo esc_attr((string) $align); ?>">
    <?php echo esc_html((string) $text); ?>
</<?php echo esc_attr($tag); ?>>
