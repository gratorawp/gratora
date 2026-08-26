<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $placeholder
 * @var bool   $required
 */
$labelText = $label !== '' ? $label : __('Email', 'giveflow-fundraising-campaigns');
?>
<label class="giveflow-block giveflow-block--email giveflow-donor__field">
    <span class="giveflow-donor__label"><?php echo esc_html($labelText); ?></span>
    <input type="email" name="email" autocomplete="email"
           placeholder="<?php echo esc_attr($placeholder); ?>"
           <?php echo esc_attr($required ? 'required' : ''); ?>>
</label>
