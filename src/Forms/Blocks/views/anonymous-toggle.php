<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var bool   $defaultOn
 */
?>
<label class="gratora-block gratora-block--anonymous gratora-anonymous">
    <input type="checkbox" name="is_anonymous" value="1" <?php echo esc_attr($defaultOn ? 'checked' : ''); ?>>
    <span class="gratora-anonymous__label"><?php echo esc_html((string) $label); ?></span>
</label>
