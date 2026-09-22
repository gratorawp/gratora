<?php
defined('ABSPATH') || exit;
/**
 * The button, and the modal it opens around the form. With the form, 'before'
 * stops where the form goes and 'after' closes what it opened; without it,
 * 'before' is the whole block.
 *
 * @var string  $part          'before' | 'after'
 * @var ?string $label
 * @var ?string $align         left|center|right
 * @var ?string $size          sm|md|lg
 * @var ?bool   $fullWidth
 * @var ?string $formSlug
 * @var ?bool   $withForm
 * @var ?string $styleVars
 */
if ($part === 'after'): ?>
</div>
                </div>
            </div>
</div>
<?php return; endif;

$alignClass = in_array($align, ['left', 'center', 'right'], true) ? "is-align-{$align}" : 'is-align-left';
$sizeClass  = 'is-size-' . (in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md');
?>
<div <?php
echo wp_kses_data(get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--donate-button ' . $alignClass . ($fullWidth ? ' is-full-width' : ''),
    'style' => $styleVars,
])));
?>
     data-block="gratora/donate-button">
    <?php if (($formSlug ?? '') !== ''): ?>
        <button type="button"
                class="gratora-donate-button <?php echo esc_attr($sizeClass);
?>"
                data-form-slug="<?php echo esc_attr($formSlug);
?>">
            <?php echo esc_html($label);
?>
        </button>
        <?php if ($withForm): ?>
            <div class="gratora-donate-modal" data-form-slug="<?php echo esc_attr($formSlug);
?>" hidden>
                <div class="gratora-donate-modal__backdrop" data-gratora-modal-close></div>
                <div class="gratora-donate-modal__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($label);
?>">
                    <button type="button" class="gratora-donate-modal__close" aria-label="<?php esc_attr_e('Close', 'gratora-donation-platform');
?>" data-gratora-modal-close>
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                            <path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                    <div class="gratora-donate-modal__body"><?php return; endif; ?>
    <?php endif; ?>
</div>
