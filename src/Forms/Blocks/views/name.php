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
$firstLabelText = $firstLabel !== '' ? $firstLabel : __('First name', 'dono-fundraising-platform');
$lastLabelText  = $lastLabel  !== '' ? $lastLabel  : __('Last name', 'dono-fundraising-platform');
?>
<div class="dono-block dono-block--name dono-donor__name">
    <label class="dono-donor__field">
        <span class="dono-donor__label"><?php echo esc_html($firstLabelText); ?></span>
        <input type="text" name="profile[first_name]" autocomplete="given-name"
               placeholder="<?php echo esc_attr($firstPlaceholder); ?>"
               <?php echo esc_attr($requireFirst ? 'required' : ''); ?>>
    </label>
    <label class="dono-donor__field">
        <span class="dono-donor__label"><?php echo esc_html($lastLabelText); ?></span>
        <input type="text" name="profile[last_name]" autocomplete="family-name"
               placeholder="<?php echo esc_attr($lastPlaceholder); ?>"
               <?php echo esc_attr($requireLast ? 'required' : ''); ?>>
    </label>
</div>
