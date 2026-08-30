<?php
defined('ABSPATH') || exit;
/**
 * @var bool $wipeOptIn
 */
?>
<div class="fundkit-deact" id="fundkit-deact" hidden>
    <div class="fundkit-deact__backdrop" data-fundkit-deact-cancel></div>
    <div class="fundkit-deact__panel" role="dialog" aria-modal="true"
         aria-labelledby="fundkit-deact-title" aria-describedby="fundkit-deact-lede">
        <h2 class="fundkit-deact__title" id="fundkit-deact-title">
            <?php esc_html_e('Deactivate FundKit', 'fundkit-fundraising-campaigns'); ?>
        </h2>

        <p class="fundkit-deact__lede" id="fundkit-deact-lede">
            <?php esc_html_e('Your donations, donors, campaigns and settings stay as they are. Switching FundKit back on picks up where you left off.', 'fundkit-fundraising-campaigns'); ?>
        </p>

        <div class="fundkit-deact__choice">
            <label class="fundkit-deact__check" for="fundkit-deact-wipe">
                <input type="checkbox" id="fundkit-deact-wipe" <?php checked($wipeOptIn); ?>>
                <span><?php esc_html_e('Delete all FundKit data as well', 'fundkit-fundraising-campaigns'); ?></span>
            </label>

            <div class="fundkit-deact__consequence" id="fundkit-deact-consequence" hidden>
                <p class="fundkit-deact__consequence-lead">
                    <?php esc_html_e('Deleted the moment you deactivate, and not recoverable:', 'fundkit-fundraising-campaigns'); ?>
                </p>
                <ul class="fundkit-deact__list">
                    <li><?php esc_html_e('Donations and refunds', 'fundkit-fundraising-campaigns'); ?></li>
                    <li><?php esc_html_e('Donors and their consent history', 'fundkit-fundraising-campaigns'); ?></li>
                    <li><?php esc_html_e('Campaigns, forms and funds', 'fundkit-fundraising-campaigns'); ?></li>
                    <li><?php esc_html_e('Receipts and annual statements', 'fundkit-fundraising-campaigns'); ?></li>
                </ul>
                <p class="fundkit-deact__consequence-foot">
                    <?php esc_html_e('Reactivating will not bring any of it back. Export anything you need first.', 'fundkit-fundraising-campaigns'); ?>
                </p>
            </div>
        </div>

        <div class="fundkit-deact__actions">
            <button type="button" class="button" data-fundkit-deact-cancel>
                <?php esc_html_e('Cancel', 'fundkit-fundraising-campaigns'); ?>
            </button>
            <button type="button" class="button button-primary" data-fundkit-deact-submit
                    data-label-keep="<?php esc_attr_e('Deactivate', 'fundkit-fundraising-campaigns'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'fundkit-fundraising-campaigns'); ?>">
                <?php esc_html_e('Deactivate', 'fundkit-fundraising-campaigns'); ?>
            </button>
        </div>
    </div>
</div>
