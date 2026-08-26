<?php
defined('ABSPATH') || exit;
/**
 * @var bool $wipeOptIn
 */
?>
<div class="giveflow-deact" id="giveflow-deact" hidden>
    <div class="giveflow-deact__backdrop" data-giveflow-deact-cancel></div>
    <div class="giveflow-deact__panel" role="dialog" aria-modal="true"
         aria-labelledby="giveflow-deact-title" aria-describedby="giveflow-deact-lede">
        <h2 class="giveflow-deact__title" id="giveflow-deact-title">
            <?php esc_html_e('Deactivate GiveFlow', 'giveflow-fundraising-campaigns'); ?>
        </h2>

        <p class="giveflow-deact__lede" id="giveflow-deact-lede">
            <?php esc_html_e('Your donations, donors, campaigns and settings stay as they are. Switching GiveFlow back on picks up where you left off.', 'giveflow-fundraising-campaigns'); ?>
        </p>

        <div class="giveflow-deact__choice">
            <label class="giveflow-deact__check" for="giveflow-deact-wipe">
                <input type="checkbox" id="giveflow-deact-wipe" <?php checked($wipeOptIn); ?>>
                <span><?php esc_html_e('Delete all GiveFlow data as well', 'giveflow-fundraising-campaigns'); ?></span>
            </label>

            <div class="giveflow-deact__consequence" id="giveflow-deact-consequence" hidden>
                <p class="giveflow-deact__consequence-lead">
                    <?php esc_html_e('Deleted the moment you deactivate, and not recoverable:', 'giveflow-fundraising-campaigns'); ?>
                </p>
                <ul class="giveflow-deact__list">
                    <li><?php esc_html_e('Donations and refunds', 'giveflow-fundraising-campaigns'); ?></li>
                    <li><?php esc_html_e('Donors and their consent history', 'giveflow-fundraising-campaigns'); ?></li>
                    <li><?php esc_html_e('Campaigns, forms and funds', 'giveflow-fundraising-campaigns'); ?></li>
                    <li><?php esc_html_e('Receipts and annual statements', 'giveflow-fundraising-campaigns'); ?></li>
                </ul>
                <p class="giveflow-deact__consequence-foot">
                    <?php esc_html_e('Reactivating will not bring any of it back. Export anything you need first.', 'giveflow-fundraising-campaigns'); ?>
                </p>
            </div>
        </div>

        <div class="giveflow-deact__actions">
            <button type="button" class="button" data-giveflow-deact-cancel>
                <?php esc_html_e('Cancel', 'giveflow-fundraising-campaigns'); ?>
            </button>
            <button type="button" class="button button-primary" data-giveflow-deact-submit
                    data-label-keep="<?php esc_attr_e('Deactivate', 'giveflow-fundraising-campaigns'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'giveflow-fundraising-campaigns'); ?>">
                <?php esc_html_e('Deactivate', 'giveflow-fundraising-campaigns'); ?>
            </button>
        </div>
    </div>
</div>
