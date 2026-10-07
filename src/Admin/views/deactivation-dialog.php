<?php
defined('ABSPATH') || exit;
/**
 * @var bool                                              $wipeOptIn
 * @var array<string,array{label:string,prompt:string}> $reasons
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

        <fieldset class="gratora-deact__why" aria-describedby="gratora-deact-why-note">
            <legend class="gratora-deact__why-title">
                <?php esc_html_e('Why are you switching it off?', 'gratora-donation-platform'); ?>
                <span class="gratora-deact__why-optional"><?php esc_html_e('Optional', 'gratora-donation-platform'); ?></span>
                <button type="button" class="button-link gratora-deact__why-clear" data-gratora-deact-clear hidden>
                    <?php esc_html_e('Clear', 'gratora-donation-platform'); ?>
                </button>
            </legend>

            <?php foreach ($reasons as $key => $reason) : ?>
                <label class="gratora-deact__reason">
                    <input type="radio" name="gratora-deact-reason" value="<?php echo esc_attr($key); ?>"
                           data-prompt="<?php echo esc_attr($reason['prompt']); ?>">
                    <span><?php echo esc_html($reason['label']); ?></span>
                </label>
            <?php endforeach; ?>

            <textarea class="gratora-deact__comment" id="gratora-deact-comment" rows="2" maxlength="500" hidden
                      aria-label="<?php esc_attr_e('Tell us more', 'gratora-donation-platform'); ?>"></textarea>

            <p class="gratora-deact__why-note" id="gratora-deact-why-note">
                <?php esc_html_e('If you pick one, it is sent to gratora.net when you deactivate, with the versions of Gratora, WordPress and PHP, how long Gratora was switched on and how far setup got. Nothing that names you or this site.', 'gratora-donation-platform'); ?>
                <a href="https://gratora.net/privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy', 'gratora-donation-platform'); ?></a>
            </p>
        </fieldset>

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
                    data-label-send="<?php esc_attr_e('Send and deactivate', 'gratora-donation-platform'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'gratora-donation-platform'); ?>">
                <?php esc_html_e('Deactivate', 'gratora-donation-platform'); ?>
            </button>
        </div>
    </div>
</div>
