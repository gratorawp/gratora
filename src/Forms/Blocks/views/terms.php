<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $terms
 * @var string $linkUrl
 * @var string $linkText
 * @var string $purpose
 */
$labelText = $label !== '' ? $label : __('I agree to the terms', 'giveflow-fundraising-campaigns');
$linkLabel = $linkText !== '' ? $linkText : __('Read the terms', 'giveflow-fundraising-campaigns');
?>
<div class="giveflow-block giveflow-block--terms giveflow-terms">
    <label class="giveflow-terms__agree">
        <input type="checkbox"
               name="consents[<?php echo esc_attr($purpose); ?>]"
               value="1"
               required>
        <span class="giveflow-terms__label">
            <?php echo esc_html($labelText); ?>
            <span class="giveflow-terms__required" aria-hidden="true">*</span>
        </span>
    </label>

    <?php if (trim($terms) !== ''): ?>
        <?php // Scrolls rather than grows: long terms would push the submit button off the screen. ?>
        <div class="giveflow-terms__text" tabindex="0" role="region" aria-label="<?php echo esc_attr($labelText); ?>">
            <?php echo wp_kses_post(wpautop($terms)); ?>
        </div>
    <?php endif; ?>

    <?php if (trim($linkUrl) !== ''): ?>
        <p class="giveflow-terms__link">
            <a href="<?php echo esc_url($linkUrl); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html($linkLabel); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
