<?php
defined('ABSPATH') || exit;
/**
 * @var bool $wipeOptIn
 */
?>
<div class="gratora-deact" id="gratora-deact" hidden>
    <div class="gratora-deact__backdrop" data-gratora-deact-cancel></div>
    <div class="gratora-deact__panel" role="dialog" aria-modal="true"
         aria-labelledby="gratora-deact-title" aria-describedby="gratora-deact-lede">
        <h2 class="gratora-deact__title" id="gratora-deact-title">
            <?php esc_html_e('Deactivate Gratora', 'gratora-donation-platform'); ?>
        </h2>

        <p class="gratora-deact__lede" id="gratora-deact-lede">
            <?php esc_html_e('Your donations, donors, campaigns and settings stay as they are. Switching Gratora back on picks up where you left off.', 'gratora-donation-platform'); ?>
        </p>

        <div class="gratora-deact__choice">
            <label class="gratora-deact__check" for="gratora-deact-wipe">
                <input type="checkbox" id="gratora-deact-wipe" <?php checked($wipeOptIn); ?>>
                <span><?php esc_html_e('Delete all Gratora data as well', 'gratora-donation-platform'); ?></span>
            </label>

            <div class="gratora-deact__consequence" id="gratora-deact-consequence" hidden>
                <p class="gratora-deact__consequence-lead">
                    <?php esc_html_e('Deleted the moment you deactivate, and not recoverable:', 'gratora-donation-platform'); ?>
                </p>
                <ul class="gratora-deact__list">
                    <li><?php esc_html_e('Donations and refunds', 'gratora-donation-platform'); ?></li>
                    <li><?php esc_html_e('Donors and their consent history', 'gratora-donation-platform'); ?></li>
                    <li><?php esc_html_e('Campaigns, forms and funds', 'gratora-donation-platform'); ?></li>
                    <li><?php esc_html_e('Receipts and annual statements', 'gratora-donation-platform'); ?></li>
                </ul>
                <p class="gratora-deact__consequence-foot">
                    <?php esc_html_e('Reactivating will not bring any of it back. Export anything you need first.', 'gratora-donation-platform'); ?>
                </p>
            </div>
        </div>

        <div class="gratora-deact__actions">
            <button type="button" class="button" data-gratora-deact-cancel>
                <?php esc_html_e('Cancel', 'gratora-donation-platform'); ?>
            </button>
            <button type="button" class="button button-primary" data-gratora-deact-submit
                    data-label-keep="<?php esc_attr_e('Deactivate', 'gratora-donation-platform'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'gratora-donation-platform'); ?>">
                <?php esc_html_e('Deactivate', 'gratora-donation-platform'); ?>
            </button>
        </div>
    </div>
</div>
