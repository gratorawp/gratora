<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $helpText
 * @var bool   $required
 * @var bool   $defaultOn
 * @var string $field
 */
?>
<label class="dono-block dono-block--checkbox dono-checkbox">
    <input
        type="checkbox"
        name="custom[<?php echo esc_attr($field); ?>]"
        value="1"
        <?php echo $defaultOn ? 'checked' : ''; ?>
        <?php echo $required ? 'required' : ''; ?>
    >
    <span class="dono-checkbox__body">
        <span class="dono-checkbox__label"><?php echo esc_html($label); ?></span>
        <?php if ($helpText !== ''): ?>
            <span class="dono-checkbox__help"><?php echo esc_html($helpText); ?></span>
        <?php endif; ?>
    </span>
</label>
