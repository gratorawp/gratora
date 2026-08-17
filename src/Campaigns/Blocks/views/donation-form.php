<?php
defined('ABSPATH') || exit;
/**
 * @var string  $mode         'front' | 'editor' | 'empty'
 * @var ?string $formHtml
 * @var ?string $previewDoc    Self-contained iframe document (editor mode)
 * @var ?string $formTitle
 * @var string  $styleVars
 */
?>
<section id="dono-form" <?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--donation-form',
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?> data-block="dono/donation-form">
    <?php if (($mode ?? 'front') === 'empty'): ?>
        <p class="dono-block__empty"><?php echo esc_html($emptyText);
?></p>
        <?php if (($notice ?? '') !== ''): ?>
            <div class="dono-block-notice"><?php echo esc_html($notice);
?></div>
        <?php endif; ?>
    <?php elseif (($mode ?? 'front') === 'editor'): ?>
        <?php if (($previewDoc ?? '') !== ''): ?>
            <iframe
                class="dono-donation-form__editor-preview"
                title="<?php echo esc_attr($formTitle ?? __('Donation form', 'dono-fundraising-platform'));
?>"
                loading="lazy"
                style="width:100%;border:0;display:block;min-height:520px"
                srcdoc="<?php echo esc_attr($previewDoc);
?>"
            ></iframe>
        <?php else: ?>
            <div class="dono-donation-form__placeholder">
                <strong><?php echo esc_html($formTitle ?? '');
?></strong>
                <span><?php esc_html_e('Donation form - shown to visitors here.', 'dono-fundraising-platform');
?></span>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        // Trusted first-party form output (shortcode -> do_blocks + bootstrap
        // script/style/JSON config). Must be echoed raw, never kses'd, or the
        // form renders as visible gibberish and never initializes.
        echo $formHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode('[dono_donation_form]') output; DonationFormShortcode::renderBlocks esc_attr()s every attribute and wp_json_encode()s the config with JSON_HEX_TAG.
        ?>
    <?php endif; ?>
</section>
