<?php
defined('ABSPATH') || exit;
/**
 * @var string $text
 * @var int    $level
 * @var string $align
 */
$tag = 'h' . max(1, min(6, (int) $level));
?>
<<?php echo tag_escape($tag); ?> class="gratora-block gratora-block--heading gratora-heading gratora-heading--<?php echo esc_attr((string) $align); ?>">
    <?php echo esc_html((string) $text); ?>
</<?php echo tag_escape($tag); ?>>
