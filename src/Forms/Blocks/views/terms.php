<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $terms
 * @var string $linkUrl
 * @var string $linkText
 * @var string $purpose
 */
$labelText = $label !== '' ? $label : __('I agree to the terms', 'dono-fundraising-platform');
$linkLabel = $linkText !== '' ? $linkText : __('Read the terms', 'dono-fundraising-platform');
?>
<div class="dono-block dono-block--terms dono-terms">
    <label class="dono-terms__agree">
        <input type="checkbox"
               name="consents[<?php echo esc_attr($purpose); ?>]"
               value="1"
               required>
        <span class="dono-terms__label">
            <?php echo esc_html($labelText); ?>
            <span class="dono-terms__required" aria-hidden="true">*</span>
        </span>
    </label>

    <?php if (trim($terms) !== ''): ?>
        <?php // Scrolls rather than grows: long terms would push the submit button off the screen. ?>
        <div class="dono-terms__text" tabindex="0" role="region" aria-label="<?php echo esc_attr($labelText); ?>">
            <?php echo wp_kses_post(wpautop($terms)); ?>
        </div>
    <?php endif; ?>

    <?php if (trim($linkUrl) !== ''): ?>
        <p class="dono-terms__link">
            <a href="<?php echo esc_url($linkUrl); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html($linkLabel); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
