<?php
defined('ABSPATH') || exit;
/**
 * @var list<string> $currencies
 * @var string       $label
 * @var string       $style  'dropdown' | 'pills'
 * @var string       $align  'left' | 'right'
 */
$wrapClasses = 'gratora-block gratora-block--currency-switcher gratora-currency'
    . ' gratora-currency--' . esc_attr($style)
    . ' gratora-currency--' . esc_attr($align);
?>
<?php
$ariaName = $label !== '' ? $label : __('Currency', 'gratora');
?>
<div class="<?php echo esc_attr($wrapClasses); ?>">
    <?php if ($label !== ''): ?>
        <span class="gratora-currency__label"><?php echo esc_html((string) $label); ?></span>
    <?php endif; ?>
    <?php if ($style === 'pills'): ?>
        <span class="gratora-currency__pills" role="radiogroup" aria-label="<?php echo esc_attr((string) $ariaName); ?>">
            <?php foreach ($currencies as $i => $code): ?>
                <label class="gratora-currency__pill">
                    <input
                        type="radio"
                        name="currency"
                        value="<?php echo esc_attr($code); ?>"
                        <?php echo esc_attr($i === 0 ? 'checked' : ''); ?>
                    >
                    <span><?php echo esc_html($code); ?></span>
                </label>
            <?php endforeach; ?>
        </span>
    <?php else: ?>
        <select name="currency" class="gratora-currency__select" aria-label="<?php echo esc_attr((string) $ariaName); ?>">
            <?php foreach ($currencies as $code): ?>
                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</div>
