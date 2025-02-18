<?php
defined('ABSPATH') || exit;
/**
 * @var list<string> $currencies
 * @var string       $label
 * @var string       $style  'dropdown' | 'pills'
 * @var string       $align  'left' | 'right'
 */
$wrapClasses = 'dono-block dono-block--currency-switcher dono-currency'
    . ' dono-currency--' . esc_attr($style)
    . ' dono-currency--' . esc_attr($align);
?>
<?php
$ariaName = $label !== '' ? $label : __('Currency', 'dono');
?>
<div class="<?php echo $wrapClasses; ?>">
    <?php if ($label !== ''): ?>
        <span class="dono-currency__label"><?php echo esc_html((string) $label); ?></span>
    <?php endif; ?>
    <?php if ($style === 'pills'): ?>
        <span class="dono-currency__pills" role="radiogroup" aria-label="<?php echo esc_attr((string) $ariaName); ?>">
            <?php foreach ($currencies as $i => $code): ?>
                <label class="dono-currency__pill">
                    <input
                        type="radio"
                        name="currency"
                        value="<?php echo esc_attr($code); ?>"
                        <?php echo $i === 0 ? 'checked' : ''; ?>
                    >
                    <span><?php echo esc_html($code); ?></span>
                </label>
            <?php endforeach; ?>
        </span>
    <?php else: ?>
        <select name="currency" class="dono-currency__select" aria-label="<?php echo esc_attr((string) $ariaName); ?>">
            <?php foreach ($currencies as $code): ?>
                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</div>
