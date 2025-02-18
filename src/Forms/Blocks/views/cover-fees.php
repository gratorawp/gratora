<?php
defined('ABSPATH') || exit;
/**
 * @var float  $percent
 * @var int    $fixed
 * @var string $label
 * @var bool   $defaultOn
 */
?>
<label class="dono-block dono-block--cover-fees dono-cover-fees"
       data-pct="<?php echo esc_attr((string) $percent); ?>"
       data-fixed="<?php echo esc_attr((string) $fixed); ?>">
    <input type="checkbox" name="cover_fees" value="1" <?php echo $defaultOn ? 'checked' : ''; ?>>
    <span class="dono-cover-fees__label"><?php echo esc_html((string) $label); ?></span>
</label>
