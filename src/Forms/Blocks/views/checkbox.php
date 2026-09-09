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
<label class="gratora-block gratora-block--checkbox gratora-checkbox">
    <input
        type="checkbox"
        name="custom[<?php echo esc_attr($field); ?>]"
        value="1"
        <?php echo esc_attr($defaultOn ? 'checked' : ''); ?>
        <?php echo esc_attr($required ? 'required' : ''); ?>
    >
    <span class="gratora-checkbox__body">
        <span class="gratora-checkbox__label"><?php echo esc_html($label); ?></span>
        <?php if ($helpText !== ''): ?>
            <span class="gratora-checkbox__help"><?php echo esc_html($helpText); ?></span>
        <?php endif; ?>
    </span>
</label>
