<?php
defined('ABSPATH') || exit;
/**
 * @var string  $mode         'front' | 'editor'
 * @var ?string $formHtml
 * @var ?string $previewDoc    Self-contained iframe document (editor mode)
 * @var ?string $formTitle
 * @var string  $themePrimary
 */
?>
<section class="dono-block dono-block--donation-form" data-block="dono/donation-form" style="--dono-accent: <?php echo esc_attr($themePrimary); ?>;">
    <?php if (($mode ?? 'front') === 'editor'): ?>
        <?php if (($previewDoc ?? '') !== ''): ?>
            <iframe
                class="dono-donation-form__editor-preview"
                title="<?php echo esc_attr($formTitle ?? __('Donation form', 'dono')); ?>"
                loading="lazy"
                style="width:100%;border:0;display:block;min-height:520px"
                srcdoc="<?php echo esc_attr($previewDoc); ?>"
            ></iframe>
        <?php else: ?>
            <div class="dono-donation-form__placeholder">
                <strong><?php echo esc_html($formTitle ?? ''); ?></strong>
                <span><?php esc_html_e('Donation form - shown to visitors here.', 'dono'); ?></span>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        // Trusted first-party form output (shortcode -> do_blocks + bootstrap
        // script/style/JSON config). Must be echoed raw, never kses'd, or the
        // form renders as visible gibberish and never initializes.
        echo $formHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    <?php endif; ?>
</section>
