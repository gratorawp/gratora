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
$labelText = $label !== '' ? $label : __('Number', 'dono');
$fieldName = $field !== '' ? $field : 'number';
?>
<label class="dono-block dono-block--number-input dono-donor__field">
    <span class="dono-donor__label"><?php echo esc_html($labelText); ?></span>
    <?php if ($helpText !== ''): ?>
        <span class="dono-donor__help"><?php echo esc_html($helpText); ?></span>
    <?php endif; ?>
    <input
        type="number"
        name="custom[<?php echo esc_attr($fieldName); ?>]"
        placeholder="<?php echo esc_attr($placeholder); ?>"
        <?php if ($min !== null): ?>min="<?php echo esc_attr((string) $min); ?>"<?php endif; ?>
        <?php if ($max !== null): ?>max="<?php echo esc_attr((string) $max); ?>"<?php endif; ?>
        step="<?php echo esc_attr((string) $step); ?>"
        <?php echo $required ? 'required' : ''; ?>>
</label>
