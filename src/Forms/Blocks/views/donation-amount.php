<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{cents:int,impact:string,preselected:bool}> $presets
 * @var bool   $allowCustom
 * @var string $currency
 * @var int    $default
 */
?>
<div class="gratora-block gratora-block--amount" data-block="gratora/donation-amount">
    <fieldset class="gratora-amount">
        <legend class="gratora-amount__legend"><?php esc_html_e('Choose an amount', 'gratora-donation-platform'); ?></legend>
        <input type="hidden" name="amount_cents" value="<?php echo esc_attr((string) $default); ?>">
        <input type="hidden" name="currency"     value="<?php echo esc_attr($currency); ?>">

        <div class="gratora-amount__presets" role="radiogroup">
            <?php foreach ($presets as $i => $preset):
                $cents   = (int) ($preset['cents'] ?? 0);
                if ($cents <= 0) continue;
                $impact  = (string) ($preset['impact'] ?? '');
                $label   = \Gratora\Foundation\Helpers\Money::compact($cents, $currency);
                $selected = $cents === (int) $default;
                $classes = 'gratora-amount__preset' . ($selected ? ' is-selected' : '');
                ?>
                <button type="button"
                        class="<?php echo esc_attr($classes); ?>"
                        data-cents="<?php echo esc_attr((string) $cents); ?>"
                        role="radio"
                        aria-checked="<?php echo esc_attr($selected ? 'true' : 'false'); ?>">
                    <span class="gratora-amount__preset-value"><?php echo esc_html($label); ?></span>
                    <?php if ($impact !== ''): ?>
                        <span class="gratora-amount__preset-impact"><?php echo esc_html($impact); ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ($allowCustom): ?>
            <label class="gratora-amount__custom">
                <span class="gratora-amount__custom-label"><?php esc_html_e('Custom amount', 'gratora-donation-platform'); ?></span>
                <input type="number"
                       class="gratora-amount__custom-input"
                       name="gratora_amount_custom"
                       step="0.01"
                       min="0.5"
                       placeholder="<?php esc_attr_e('0.00', 'gratora-donation-platform'); ?>"
                       inputmode="decimal">
            </label>
        <?php endif; ?>
    </fieldset>
</div>
