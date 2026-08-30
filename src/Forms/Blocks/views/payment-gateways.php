<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{id:string,label:string,description:string}> $options
 */
?>
<div class="fundkit-block fundkit-block--gateways" data-block="fundkit/payment-gateways">
    <fieldset class="fundkit-gateways">
        <legend class="fundkit-gateways__legend"><?php esc_html_e('Payment method', 'fundkit-fundraising-campaigns'); ?></legend>
        <?php foreach ($options as $i => $o):
            $id    = (string) ($o['id'] ?? '');
            if ($id === '') continue;
            $label = (string) ($o['label'] ?? $id);
            $desc  = (string) ($o['description'] ?? '');
            ?>
            <label class="fundkit-gateways__option">
                <input type="radio"
                       name="gateway"
                       value="<?php echo esc_attr($id); ?>"
                       <?php echo esc_attr($i === 0 ? 'checked' : ''); ?>>
                <span class="fundkit-gateways__body">
                    <span class="fundkit-gateways__label"><?php echo esc_html($label); ?></span>
                    <?php if ($desc !== ''): ?>
                        <span class="fundkit-gateways__desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </fieldset>
</div>
