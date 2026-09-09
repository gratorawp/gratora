<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Email', 'gratora');
?>
<label class="gratora-block gratora-block--email gratora-donor__field">
    <span class="gratora-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="email" name="email" autocomplete="email"
           placeholder="<?php echo esc_attr($placeholder); ?>"
           <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
