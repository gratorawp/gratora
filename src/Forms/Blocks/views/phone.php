<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Phone', 'gratora-donation-platform');
?>
<label class="gratora-block gratora-block--phone gratora-donor__field">
    <span class="gratora-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="tel" name="profile[phone]" autocomplete="tel"
           placeholder="<?php echo esc_attr($placeholder); ?>"
           <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
