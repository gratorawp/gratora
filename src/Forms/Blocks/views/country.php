<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Country', 'gratora-donation-platform');
$placeholderText = $placeholder !== '' ? $placeholder : 'DE';
?>
<label class="gratora-block gratora-block--country gratora-donor__field">
    <span class="gratora-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="text" name="profile[country]" autocomplete="country" maxlength="2" pattern="[A-Za-z]{2}"
           placeholder="<?php echo esc_attr($placeholderText); ?>"
           <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
