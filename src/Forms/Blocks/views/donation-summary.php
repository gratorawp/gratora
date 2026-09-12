<?php
defined('ABSPATH') || exit;
/**
 * @var bool $showDonor
 * @var bool $showGateway
 */
?>
<div class="gratora-block gratora-block--summary gratora-form__confirm" data-block="gratora/donation-summary"
     data-show-donor="<?php echo esc_attr($showDonor ? '1' : '0'); ?>"
     data-show-gateway="<?php echo esc_attr($showGateway ? '1' : '0'); ?>">
    <dl class="gratora-form__summary">
        <div class="gratora-form__summary-row">
            <dt><?php esc_html_e('Amount', 'gratora-donation-platform'); ?></dt>
            <dd class="gratora-form__summary-amount"></dd>
        </div>
        <div class="gratora-form__summary-row gratora-form__summary-row--total">
            <dt><?php esc_html_e('Total', 'gratora-donation-platform'); ?></dt>
            <dd class="gratora-form__summary-amount"></dd>
        </div>
    </dl>
</div>
