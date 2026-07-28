<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $helpText
 * @var string $statement
 */
$labelText = $label !== '' ? $label : __('Boost your donation by 25% at no cost to you', 'dono');
?>
<fieldset class="dono-block dono-block--gift-aid dono-gift-aid">
    <legend class="dono-gift-aid__legend"><?php echo esc_html($labelText); ?></legend>
    <?php if ($helpText !== ''): ?>
        <p class="dono-gift-aid__help"><?php echo esc_html($helpText); ?></p>
    <?php endif; ?>

    <label class="dono-gift-aid__declaration">
        <input type="checkbox" name="gift_aid" value="1">
        <span class="dono-gift-aid__statement"><?php echo esc_html($statement); ?></span>
    </label>

    <p class="dono-gift-aid__note">
        <?php esc_html_e(
            'We need your home address to claim Gift Aid, so please make sure it is filled in above.',
            'dono'
        ); ?>
    </p>
</fieldset>
