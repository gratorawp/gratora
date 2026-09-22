<?php
defined('ABSPATH') || exit;
/**
 * The block's wrapper. The form or its preview goes between 'before' and
 * 'after'; with no form to show, 'before' carries the empty card instead.
 *
 * @var string  $part       'before' | 'after'
 * @var ?string $emptyText  the empty card's text
 * @var ?string $notice
 * @var ?string $styleVars
 */
if ($part === 'after'): ?>
</section>
<?php return; endif; ?>
<section id="gratora-form" <?php
echo wp_kses_data(get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--donation-form',
    'style' => $styleVars,
])));
?> data-block="gratora/donation-form">
    <?php if (($emptyText ?? '') !== ''): ?>
        <p class="gratora-block__empty"><?php echo esc_html($emptyText);
?></p>
        <?php if (($notice ?? '') !== ''): ?>
            <div class="gratora-block-notice"><?php echo esc_html($notice);
?></div>
        <?php endif; ?>
    <?php endif; ?>
