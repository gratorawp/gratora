<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Email', 'fundkit-fundraising-campaigns');
?>
<label class="fundkit-block fundkit-block--email fundkit-donor__field">
    <span class="fundkit-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="email" name="email" autocomplete="email"
           placeholder="<?php echo esc_attr($placeholder); ?>"
           <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
