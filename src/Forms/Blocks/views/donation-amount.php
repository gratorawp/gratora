<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{cents:int,impact:string,preselected:bool}> $presets
 * @var bool   $allowCustom
 * @var string $currency
 * @var int    $default
 */
?>
<div class="fundkit-block fundkit-block--amount" data-block="fundkit/donation-amount">
    <fieldset class="fundkit-amount">
        <legend class="fundkit-amount__legend"><?php esc_html_e('Choose an amount', 'fundkit-fundraising-campaigns'); ?></legend>
        <input type="hidden" name="amount_cents" value="<?php echo esc_attr((string) $default); ?>">
        <input type="hidden" name="currency"     value="<?php echo esc_attr($currency); ?>">

        <div class="fundkit-amount__presets" role="radiogroup">
            <?php foreach ($presets as $i => $preset):
                $cents   = (int) ($preset['cents'] ?? 0);
                if ($cents <= 0) continue;
                $impact  = (string) ($preset['impact'] ?? '');
                $label   = \FundKit\Foundation\Helpers\Money::compact($cents, $currency);
                $selected = $cents === (int) $default;
                $classes = 'fundkit-amount__preset' . ($selected ? ' is-selected' : '');
                ?>
                <button type="button"
                        class="<?php echo esc_attr($classes); ?>"
                        data-cents="<?php echo esc_attr((string) $cents); ?>"
                        role="radio"
                        aria-checked="<?php echo esc_attr($selected ? 'true' : 'false'); ?>">
                    <span class="fundkit-amount__preset-value"><?php echo esc_html($label); ?></span>
                    <?php if ($impact !== ''): ?>
                        <span class="fundkit-amount__preset-impact"><?php echo esc_html($impact); ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ($allowCustom): ?>
            <label class="fundkit-amount__custom">
                <span class="fundkit-amount__custom-label"><?php esc_html_e('Custom amount', 'fundkit-fundraising-campaigns'); ?></span>
                <input type="number"
                       class="fundkit-amount__custom-input"
                       name="fundkit_amount_custom"
                       step="0.01"
                       min="0.5"
                       placeholder="<?php esc_attr_e('0.00', 'fundkit-fundraising-campaigns'); ?>"
                       inputmode="decimal">
            </label>
        <?php endif; ?>
    </fieldset>
</div>
