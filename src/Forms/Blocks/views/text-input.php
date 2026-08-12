<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var string $helpText
 * @var bool   $required
 * @var int    $maxLength
 * @var string $pattern
 * @var string $field
 */
$labelText = $label !== '' ? $label : __('Text', 'dono-fundraising-platform');
$fieldName = $field !== '' ? $field : 'text';
?>
<label class="dono-block dono-block--text-input dono-donor__field">
    <span class="dono-donor__label"><?php echo esc_html($labelText); ?></span>
    <?php if ($helpText !== ''): ?>
        <span class="dono-donor__help"><?php echo esc_html($helpText); ?></span>
    <?php endif; ?>
    <input
        type="text"
        name="custom[<?php echo esc_attr($fieldName); ?>]"
        placeholder="<?php echo esc_attr($placeholder); ?>"
        <?php if ($maxLength > 0): ?>maxlength="<?php echo esc_attr((string) $maxLength); ?>"<?php endif; ?>
        <?php if ($pattern !== ''): ?>pattern="<?php echo esc_attr($pattern); ?>"<?php endif; ?>
        <?php echo $required ? 'required' : ''; ?>>
</label>
