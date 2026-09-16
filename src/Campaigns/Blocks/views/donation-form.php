<?php
defined('ABSPATH') || exit;
/**
 * @var string  $mode         'front' | 'editor' | 'empty'
 * @var ?string $emptyText     Shown in 'empty' mode
 * @var ?string $notice
 * @var ?string $formSlug
 * @var ?string $previewDoc    Self-contained iframe document (editor mode)
 * @var ?string $formTitle
 * @var string  $styleVars
 */
?>
<section id="gratora-form" <?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--donation-form',
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="gratora/donation-form">
    <?php if (($mode ?? 'front') === 'empty'): ?>
        <p class="gratora-block__empty"><?php echo esc_html($emptyText ?? '');
?></p>
        <?php if (($notice ?? '') !== ''): ?>
            <div class="gratora-block-notice"><?php echo esc_html($notice);
?></div>
        <?php endif; ?>
    <?php elseif (($mode ?? 'front') === 'editor'): ?>
        <iframe
            class="gratora-donation-form__editor-preview"
            title="<?php echo esc_attr($formTitle ?? __('Donation form', 'gratora-donation-platform'));
?>"
            loading="lazy"
            style="width:100%;border:0;display:block;min-height:520px"
            srcdoc="<?php echo esc_attr($previewDoc ?? '');
?>"
        ></iframe>
    <?php else: ?>
        <?php echo do_shortcode('[gratora_donation_form slug="' . esc_attr($formSlug ?? '') . '"]'); ?>
    <?php endif; ?>
</section>
