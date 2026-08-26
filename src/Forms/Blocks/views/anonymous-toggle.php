<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var bool   $defaultOn
 */
?>
<label class="giveflow-block giveflow-block--anonymous giveflow-anonymous">
    <input type="checkbox" name="is_anonymous" value="1" <?php echo esc_attr($defaultOn ? 'checked' : ''); ?>>
    <span class="giveflow-anonymous__label"><?php echo esc_html((string) $label); ?></span>
</label>
