<?php
defined('ABSPATH') || exit;
/**
 * @var bool $wipeOptIn
 */
?>
<div class="dono-deact" id="dono-deact" hidden>
    <div class="dono-deact__backdrop" data-dono-deact-cancel></div>
    <div class="dono-deact__panel" role="dialog" aria-modal="true"
         aria-labelledby="dono-deact-title" aria-describedby="dono-deact-lede">
        <h2 class="dono-deact__title" id="dono-deact-title">
            <?php esc_html_e('Deactivate Dono', 'dono-fundraising-platform'); ?>
        </h2>

        <p class="dono-deact__lede" id="dono-deact-lede">
            <?php esc_html_e('Your donations, donors, campaigns and settings stay as they are. Switching Dono back on picks up where you left off.', 'dono-fundraising-platform'); ?>
        </p>

        <div class="dono-deact__choice">
            <label class="dono-deact__check" for="dono-deact-wipe">
                <input type="checkbox" id="dono-deact-wipe" <?php checked($wipeOptIn); ?>>
                <span><?php esc_html_e('Delete all Dono data as well', 'dono-fundraising-platform'); ?></span>
            </label>

            <div class="dono-deact__consequence" id="dono-deact-consequence" hidden>
                <p class="dono-deact__consequence-lead">
                    <?php esc_html_e('Deleted the moment you deactivate, and not recoverable:', 'dono-fundraising-platform'); ?>
                </p>
                <ul class="dono-deact__list">
                    <li><?php esc_html_e('Donations and refunds', 'dono-fundraising-platform'); ?></li>
                    <li><?php esc_html_e('Donors and their consent history', 'dono-fundraising-platform'); ?></li>
                    <li><?php esc_html_e('Campaigns, forms and funds', 'dono-fundraising-platform'); ?></li>
                    <li><?php esc_html_e('Receipts and annual statements', 'dono-fundraising-platform'); ?></li>
                </ul>
                <p class="dono-deact__consequence-foot">
                    <?php esc_html_e('Reactivating will not bring any of it back. Export anything you need first.', 'dono-fundraising-platform'); ?>
                </p>
            </div>
        </div>

        <div class="dono-deact__actions">
            <button type="button" class="button" data-dono-deact-cancel>
                <?php esc_html_e('Cancel', 'dono-fundraising-platform'); ?>
            </button>
            <button type="button" class="button button-primary" data-dono-deact-submit
                    data-label-keep="<?php esc_attr_e('Deactivate', 'dono-fundraising-platform'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'dono-fundraising-platform'); ?>">
                <?php esc_html_e('Deactivate', 'dono-fundraising-platform'); ?>
            </button>
        </div>
    </div>
</div>
