<?php
defined('ABSPATH') || exit;
/**
 * @var bool $showDonor
 * @var bool $showGateway
 */
?>
<div class="fundkit-block fundkit-block--summary fundkit-form__confirm" data-block="fundkit/donation-summary"
     data-show-donor="<?php echo esc_attr($showDonor ? '1' : '0'); ?>"
     data-show-gateway="<?php echo esc_attr($showGateway ? '1' : '0'); ?>">
    <dl class="fundkit-form__summary">
        <div class="fundkit-form__summary-row">
            <dt><?php esc_html_e('Amount', 'fundraising-toolkit'); ?></dt>
            <dd class="fundkit-form__summary-amount"></dd>
        </div>
        <div class="fundkit-form__summary-row fundkit-form__summary-row--total">
            <dt><?php esc_html_e('Total', 'fundraising-toolkit'); ?></dt>
            <dd class="fundkit-form__summary-amount"></dd>
        </div>
    </dl>
</div>
