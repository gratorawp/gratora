<?php
defined('ABSPATH') || exit;
/**
 * @var string $label
 * @var string $terms
 * @var string $linkUrl
 * @var string $linkText
 * @var string $purpose
 */
$labelText = $label !== '' ? $label : __('I agree to the terms', 'gratora');
$linkLabel = $linkText !== '' ? $linkText : __('Read the terms', 'gratora');
?>
<div class="gratora-block gratora-block--terms gratora-terms">
    <label class="gratora-terms__agree">
        <input type="checkbox"
               name="consents[<?php echo esc_attr($purpose); ?>]"
               value="1"
               required>
        <span class="gratora-terms__label">
            <?php echo esc_html($labelText); ?>
            <span class="gratora-terms__required" aria-hidden="true">*</span>
        </span>
    </label>

    <?php if (trim($terms) !== ''): ?>
        <?php // Scrolls rather than grows: long terms would push the submit button off the screen. ?>
        <div class="gratora-terms__text" tabindex="0" role="region" aria-label="<?php echo esc_attr($labelText); ?>">
            <?php echo wp_kses_post(wpautop($terms)); ?>
        </div>
    <?php endif; ?>

    <?php if (trim($linkUrl) !== ''): ?>
        <p class="gratora-terms__link">
            <a href="<?php echo esc_url($linkUrl); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html($linkLabel); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
