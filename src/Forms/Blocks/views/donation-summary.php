<?php
defined('ABSPATH') || exit;
/**
 * @var bool $showDonor
 * @var bool $showGateway
 */
?>
<div class="dono-block dono-block--summary dono-form__confirm" data-block="dono/donation-summary"
     data-show-donor="<?php echo $showDonor ? '1' : '0'; ?>"
     data-show-gateway="<?php echo $showGateway ? '1' : '0'; ?>">
    <dl class="dono-form__summary">
        <div class="dono-form__summary-row">
            <dt><?php esc_html_e('Amount', 'dono'); ?></dt>
            <dd class="dono-form__summary-amount"></dd>
        </div>
        <div class="dono-form__summary-row dono-form__summary-row--total">
            <dt><?php esc_html_e('Total', 'dono'); ?></dt>
            <dd class="dono-form__summary-amount"></dd>
        </div>
    </dl>
</div>
