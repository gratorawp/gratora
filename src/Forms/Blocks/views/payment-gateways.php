<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{id:string,label:string,description:string}> $options
 */
?>
<div class="giveflow-block giveflow-block--gateways" data-block="giveflow/payment-gateways">
    <fieldset class="giveflow-gateways">
        <legend class="giveflow-gateways__legend"><?php esc_html_e('Payment method', 'giveflow-fundraising-campaigns'); ?></legend>
        <?php foreach ($options as $i => $o):
            $id    = (string) ($o['id'] ?? '');
            if ($id === '') continue;
            $label = (string) ($o['label'] ?? $id);
            $desc  = (string) ($o['description'] ?? '');
            ?>
            <label class="giveflow-gateways__option">
                <input type="radio"
                       name="gateway"
                       value="<?php echo esc_attr($id); ?>"
                       <?php echo esc_attr($i === 0 ? 'checked' : ''); ?>>
                <span class="giveflow-gateways__body">
                    <span class="giveflow-gateways__label"><?php echo esc_html($label); ?></span>
                    <?php if ($desc !== ''): ?>
                        <span class="giveflow-gateways__desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </fieldset>
</div>
