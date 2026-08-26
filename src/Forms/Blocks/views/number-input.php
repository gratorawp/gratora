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
$labelText = $label !== '' ? $label : __('Number', 'giveflow-fundraising-campaigns');
$fieldName = $field !== '' ? $field : 'number';
?>
<label class="giveflow-block giveflow-block--number-input giveflow-donor__field">
    <span class="giveflow-donor__label"><?php echo esc_html($labelText); ?></span>
    <?php if ($helpText !== ''): ?>
        <span class="giveflow-donor__help"><?php echo esc_html($helpText); ?></span>
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
