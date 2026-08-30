<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Country', 'fundkit-fundraising-campaigns');
$placeholderText = $placeholder !== '' ? $placeholder : 'DE';
?>
<label class="fundkit-block fundkit-block--country fundkit-donor__field">
    <span class="fundkit-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="text" name="profile[country]" autocomplete="country" maxlength="2" pattern="[A-Za-z]{2}"
           placeholder="<?php echo esc_attr($placeholderText); ?>"
           <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
