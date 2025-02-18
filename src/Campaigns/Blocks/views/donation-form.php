<?php
defined('ABSPATH') || exit;
/**
 * @var string  $mode         'front' | 'editor'
 * @var ?string $formHtml
 * @var ?string $formTitle
 * @var string  $align
 * @var string  $themePrimary
 */
?>
<section class="dono-block dono-block--donation-form is-align-<?php echo esc_attr($align); ?>" data-block="dono/donation-form" style="--dono-accent: <?php echo esc_attr($themePrimary); ?>;">
    <?php if (($mode ?? 'front') === 'editor'): ?>
        <div class="dono-donation-form__placeholder">
            <strong><?php echo esc_html($formTitle ?? ''); ?></strong>
            <span><?php esc_html_e('Donation form - shown to visitors here.', 'dono'); ?></span>
        </div>
    <?php else: ?>
        <?php
        // Trusted first-party form output (shortcode -> do_blocks + bootstrap
        // script/style/JSON config). Must be echoed raw, never kses'd, or the
        // form renders as visible gibberish and never initializes.
        echo $formHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    <?php endif; ?>
</section>
