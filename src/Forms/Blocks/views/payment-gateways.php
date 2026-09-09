<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{id:string,label:string,description:string}> $options
 */
?>
<div class="gratora-block gratora-block--gateways" data-block="gratora/payment-gateways">
    <fieldset class="gratora-gateways">
        <legend class="gratora-gateways__legend"><?php esc_html_e('Payment method', 'gratora'); ?></legend>
        <?php foreach ($options as $i => $o):
            $id    = (string) ($o['id'] ?? '');
            if ($id === '') continue;
            $label = (string) ($o['label'] ?? $id);
            $desc  = (string) ($o['description'] ?? '');
            ?>
            <label class="gratora-gateways__option">
                <input type="radio"
                       name="gateway"
                       value="<?php echo esc_attr($id); ?>"
                       <?php echo esc_attr($i === 0 ? 'checked' : ''); ?>>
                <span class="gratora-gateways__body">
                    <span class="gratora-gateways__label"><?php echo esc_html($label); ?></span>
                    <?php if ($desc !== ''): ?>
                        <span class="gratora-gateways__desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </fieldset>
</div>
