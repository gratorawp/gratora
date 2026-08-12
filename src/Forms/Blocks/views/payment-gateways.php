<?php
defined('ABSPATH') || exit;
/**
 * @var list<array{id:string,label:string,description:string}> $options
 */
?>
<div class="dono-block dono-block--gateways" data-block="dono/payment-gateways">
    <fieldset class="dono-gateways">
        <legend class="dono-gateways__legend"><?php esc_html_e('Payment method', 'dono-fundraising-platform'); ?></legend>
        <?php foreach ($options as $i => $o):
            $id    = (string) ($o['id'] ?? '');
            if ($id === '') continue;
            $label = (string) ($o['label'] ?? $id);
            $desc  = (string) ($o['description'] ?? '');
            ?>
            <label class="dono-gateways__option">
                <input type="radio"
                       name="gateway"
                       value="<?php echo esc_attr($id); ?>"
                       <?php echo $i === 0 ? 'checked' : ''; ?>>
                <span class="dono-gateways__body">
                    <span class="dono-gateways__label"><?php echo esc_html($label); ?></span>
                    <?php if ($desc !== ''): ?>
                        <span class="dono-gateways__desc"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </fieldset>
</div>
