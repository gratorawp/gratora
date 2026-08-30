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
<label class="fundkit-block fundkit-block--checkbox fundkit-checkbox">
    <input
        type="checkbox"
        name="custom[<?php echo esc_attr($field); ?>]"
        value="1"
        <?php echo esc_attr($defaultOn ? 'checked' : ''); ?>
        <?php echo esc_attr($required ? 'required' : ''); ?>
    >
    <span class="fundkit-checkbox__body">
        <span class="fundkit-checkbox__label"><?php echo esc_html($label); ?></span>
        <?php if ($helpText !== ''): ?>
            <span class="fundkit-checkbox__help"><?php echo esc_html($helpText); ?></span>
        <?php endif; ?>
    </span>
</label>
