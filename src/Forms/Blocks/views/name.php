<?php
defined('ABSPATH') || exit;
/**
 * @var string $firstLabel
 * @var string $lastLabel
 * @var string $firstPlaceholder
 * @var string $lastPlaceholder
 * @var bool   $requireFirst
 * @var bool   $requireLast
 */
$firstLabelText = $firstLabel !== '' ? $firstLabel : __('First name', 'fundkit-fundraising-campaigns');
$lastLabelText  = $lastLabel  !== '' ? $lastLabel  : __('Last name', 'fundkit-fundraising-campaigns');
?>
<div class="fundkit-block fundkit-block--name fundkit-donor__name">
    <label class="fundkit-donor__field">
        <span class="fundkit-donor__label"><?php echo esc_html($firstLabelText); ?></span>
        <input type="text" name="profile[first_name]" autocomplete="given-name"
               placeholder="<?php echo esc_attr($firstPlaceholder); ?>"
               <?php echo esc_attr($requireFirst ? 'required' : ''); ?>>
    </label>
    <label class="fundkit-donor__field">
        <span class="fundkit-donor__label"><?php echo esc_html($lastLabelText); ?></span>
        <input type="text" name="profile[last_name]" autocomplete="family-name"
               placeholder="<?php echo esc_attr($lastPlaceholder); ?>"
               <?php echo esc_attr($requireLast ? 'required' : ''); ?>>
    </label>
</div>
