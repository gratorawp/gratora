<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Country', 'dono');
$placeholderText = $placeholder !== '' ? $placeholder : 'DE';
?>
<label class="dono-block dono-block--country dono-donor__field">
    <span class="dono-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="text" name="profile[country]" autocomplete="country" maxlength="2" pattern="[A-Za-z]{2}"
           placeholder="<?php echo esc_attr($placeholderText); ?>"
           <?php echo $required ? 'required' : ''; ?>>
</label>
