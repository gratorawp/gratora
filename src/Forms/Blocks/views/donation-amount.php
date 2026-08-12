<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{cents:int,impact:string,preselected:bool}> $presets
 * @var bool   $allowCustom
 * @var string $currency
 * @var int    $default
 */
?>
<div class="dono-block dono-block--amount" data-block="dono/donation-amount">
    <fieldset class="dono-amount">
        <legend class="dono-amount__legend"><?php esc_html_e('Choose an amount', 'dono-fundraising-platform'); ?></legend>
        <input type="hidden" name="amount_cents" value="<?php echo esc_attr((string) $default); ?>">
        <input type="hidden" name="currency"     value="<?php echo esc_attr($currency); ?>">

        <div class="dono-amount__presets" role="radiogroup">
            <?php foreach ($presets as $i => $preset):
                $cents   = (int) ($preset['cents'] ?? 0);
                if ($cents <= 0) continue;
                $impact  = (string) ($preset['impact'] ?? '');
                $label   = \Dono\Foundation\Helpers\Money::compact($cents, $currency);
                $selected = $cents === (int) $default;
                $classes = 'dono-amount__preset' . ($selected ? ' is-selected' : '');
                ?>
                <button type="button"
                        class="<?php echo esc_attr($classes); ?>"
                        data-cents="<?php echo esc_attr((string) $cents); ?>"
                        role="radio"
                        aria-checked="<?php echo $selected ? 'true' : 'false'; ?>">
                    <span class="dono-amount__preset-value"><?php echo esc_html($label); ?></span>
                    <?php if ($impact !== ''): ?>
                        <span class="dono-amount__preset-impact"><?php echo esc_html($impact); ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ($allowCustom): ?>
            <label class="dono-amount__custom">
                <span class="dono-amount__custom-label"><?php esc_html_e('Custom amount', 'dono-fundraising-platform'); ?></span>
                <input type="number"
                       class="dono-amount__custom-input"
                       name="dono_amount_custom"
                       step="0.01"
                       min="0.5"
                       placeholder="<?php esc_attr_e('0.00', 'dono-fundraising-platform'); ?>"
                       inputmode="decimal">
            </label>
        <?php endif; ?>
    </fieldset>
</div>
