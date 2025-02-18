<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Phone', 'dono');
?>
<label class="dono-block dono-block--phone dono-donor__field">
    <span class="dono-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="tel" name="profile[phone]" autocomplete="tel"
           placeholder="<?php echo esc_attr($placeholder); ?>"
           <?php echo $required ? 'required' : ''; ?>>
</label>
