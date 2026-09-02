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
            <?php esc_html_e('Deactivate Fundraising Toolkit', 'fundraising-toolkit'); ?>
        </h2>

        <p class="fundkit-deact__lede" id="fundkit-deact-lede">
            <?php esc_html_e('Your donations, donors, campaigns and settings stay as they are. Switching Fundraising Toolkit back on picks up where you left off.', 'fundraising-toolkit'); ?>
        </p>

        <div class="fundkit-deact__choice">
            <label class="fundkit-deact__check" for="fundkit-deact-wipe">
                <input type="checkbox" id="fundkit-deact-wipe" <?php checked($wipeOptIn); ?>>
                <span><?php esc_html_e('Delete all Fundraising Toolkit data as well', 'fundraising-toolkit'); ?></span>
            </label>

            <div class="fundkit-deact__consequence" id="fundkit-deact-consequence" hidden>
                <p class="fundkit-deact__consequence-lead">
                    <?php esc_html_e('Deleted the moment you deactivate, and not recoverable:', 'fundraising-toolkit'); ?>
                </p>
                <ul class="fundkit-deact__list">
                    <li><?php esc_html_e('Donations and refunds', 'fundraising-toolkit'); ?></li>
                    <li><?php esc_html_e('Donors and their consent history', 'fundraising-toolkit'); ?></li>
                    <li><?php esc_html_e('Campaigns, forms and funds', 'fundraising-toolkit'); ?></li>
                    <li><?php esc_html_e('Receipts and annual statements', 'fundraising-toolkit'); ?></li>
                </ul>
                <p class="fundkit-deact__consequence-foot">
                    <?php esc_html_e('Reactivating will not bring any of it back. Export anything you need first.', 'fundraising-toolkit'); ?>
                </p>
            </div>
        </div>

        <div class="fundkit-deact__actions">
            <button type="button" class="button" data-fundkit-deact-cancel>
                <?php esc_html_e('Cancel', 'fundraising-toolkit'); ?>
            </button>
            <button type="button" class="button button-primary" data-fundkit-deact-submit
                    data-label-keep="<?php esc_attr_e('Deactivate', 'fundraising-toolkit'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'fundraising-toolkit'); ?>">
                <?php esc_html_e('Deactivate', 'fundraising-toolkit'); ?>
            </button>
        </div>
    </div>
</div>
