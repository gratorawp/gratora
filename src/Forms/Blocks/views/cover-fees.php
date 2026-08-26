<?php
defined('ABSPATH') || exit;
/**
 * @var float  $percent
 * @var int    $fixed
 * @var string $label
 * @var bool   $defaultOn
 */
?>
<label class="giveflow-block giveflow-block--cover-fees giveflow-cover-fees"
       data-pct="<?php echo esc_attr((string) $percent); ?>"
       data-fixed="<?php echo esc_attr((string) $fixed); ?>">
    <input type="checkbox" name="cover_fees" value="1" <?php echo esc_attr($defaultOn ? 'checked' : ''); ?>>
    <span class="giveflow-cover-fees__label"><?php echo esc_html((string) $label); ?></span>
</label>
