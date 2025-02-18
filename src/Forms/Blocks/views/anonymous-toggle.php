<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var bool   $defaultOn
 */
?>
<label class="dono-block dono-block--anonymous dono-anonymous">
    <input type="checkbox" name="is_anonymous" value="1" <?php echo $defaultOn ? 'checked' : ''; ?>>
    <span class="dono-anonymous__label"><?php echo esc_html((string) $label); ?></span>
</label>
