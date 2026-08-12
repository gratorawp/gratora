<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $helpText
 * @var bool   $required
 * @var string $minDate
 * @var string $maxDate
 * @var string $field
 */
$labelText = $label !== '' ? $label : __('Date', 'dono-fundraising-platform');
$fieldName = $field !== '' ? $field : 'date';
?>
<label class="dono-block dono-block--date dono-donor__field">
    <span class="dono-donor__label"><?php echo esc_html($labelText); ?></span>
    <?php if ($helpText !== ''): ?>
        <span class="dono-donor__help"><?php echo esc_html($helpText); ?></span>
    <?php endif; ?>
    <input
        type="date"
        name="custom[<?php echo esc_attr($fieldName); ?>]"
        <?php if ($minDate !== ''): ?>min="<?php echo esc_attr($minDate); ?>"<?php endif; ?>
        <?php if ($maxDate !== ''): ?>max="<?php echo esc_attr($maxDate); ?>"<?php endif; ?>
        <?php echo $required ? 'required' : ''; ?>>
</label>
