<?php
defined('ABSPATH') || exit;
/**
 * @var string     $label
 * @var string     $placeholder
 * @var string     $helpText
 * @var bool       $required
 * @var float|null $min
 * @var float|null $max
 * @var float      $step
 * @var string     $field
 */
$labelText = $label !== '' ? $label : __('Number', 'fundkit-fundraising-campaigns');
$fieldName = $field !== '' ? $field : 'number';
?>
<label class="fundkit-block fundkit-block--number-input fundkit-donor__field">
    <span class="fundkit-donor__label"><?php echo esc_html($labelText); ?></span>
    <?php if ($helpText !== ''): ?>
        <span class="fundkit-donor__help"><?php echo esc_html($helpText); ?></span>
    <?php endif; ?>
    <input
        type="number"
        name="custom[<?php echo esc_attr($fieldName); ?>]"
        placeholder="<?php echo esc_attr($placeholder); ?>"
        <?php if ($min !== null): ?>min="<?php echo esc_attr((string) $min); ?>"<?php endif; ?>
        <?php if ($max !== null): ?>max="<?php echo esc_attr((string) $max); ?>"<?php endif; ?>
        step="<?php echo esc_attr((string) $step); ?>"
        <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
