<?php
defined('ABSPATH') || exit;
/**
 * @var bool $showDonor
 * @var bool $showGateway
 */
?>
<div class="giveflow-block giveflow-block--summary giveflow-form__confirm" data-block="giveflow/donation-summary"
     data-show-donor="<?php echo esc_attr($showDonor ? '1' : '0'); ?>"
     data-show-gateway="<?php echo esc_attr($showGateway ? '1' : '0'); ?>">
    <dl class="giveflow-form__summary">
        <div class="giveflow-form__summary-row">
            <dt><?php esc_html_e('Amount', 'giveflow-fundraising-campaigns'); ?></dt>
            <dd class="giveflow-form__summary-amount"></dd>
        </div>
        <div class="giveflow-form__summary-row giveflow-form__summary-row--total">
            <dt><?php esc_html_e('Total', 'giveflow-fundraising-campaigns'); ?></dt>
            <dd class="giveflow-form__summary-amount"></dd>
        </div>
    </dl>
</div>
