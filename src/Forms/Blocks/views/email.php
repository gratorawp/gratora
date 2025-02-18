<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Email', 'dono');
?>
<label class="dono-block dono-block--email dono-donor__field">
    <span class="dono-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="email" name="email" autocomplete="email"
           placeholder="<?php echo esc_attr($placeholder); ?>"
           <?php echo $required ? 'required' : ''; ?>>
</label>
