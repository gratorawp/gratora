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
$firstLabelText = $firstLabel !== '' ? $firstLabel : __('First name', 'gratora');
$lastLabelText  = $lastLabel  !== '' ? $lastLabel  : __('Last name', 'gratora');
?>
<div class="gratora-block gratora-block--name gratora-donor__name">
    <label class="gratora-donor__field">
        <span class="gratora-donor__label"><?php echo esc_html($firstLabelText); ?></span>
        <input type="text" name="profile[first_name]" autocomplete="given-name"
               placeholder="<?php echo esc_attr($firstPlaceholder); ?>"
               <?php echo esc_attr($requireFirst ? 'required' : ''); ?>>
    </label>
    <label class="gratora-donor__field">
        <span class="gratora-donor__label"><?php echo esc_html($lastLabelText); ?></span>
        <input type="text" name="profile[last_name]" autocomplete="family-name"
               placeholder="<?php echo esc_attr($lastPlaceholder); ?>"
               <?php echo esc_attr($requireLast ? 'required' : ''); ?>>
    </label>
</div>
