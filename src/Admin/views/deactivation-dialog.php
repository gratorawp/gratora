<?php
defined('ABSPATH') || exit;
/**
 * @var bool                                              $wipeOptIn
 * @var array<string,array{label:string,prompt:string}> $reasons       none when the question is not asked
 * @var int                                               $commentLength
 */
?>
<div class="gratora-deact" id="gratora-deact" hidden>
    <div class="gratora-deact__backdrop" data-gratora-deact-cancel></div>
    <div class="gratora-deact__panel" role="dialog" aria-modal="true" tabindex="-1"
         aria-labelledby="gratora-deact-title" aria-describedby="gratora-deact-lede">
        <h2 class="gratora-deact__title" id="gratora-deact-title">
            <?php esc_html_e('Deactivate Gratora', 'gratora-donation-platform'); ?>
        </h2>

        <p class="gratora-deact__lede" id="gratora-deact-lede">
            <?php esc_html_e('Your donations, donors, campaigns and settings stay as they are. Switching Gratora back on picks up where you left off.', 'gratora-donation-platform'); ?>
        </p>

        <?php if ($reasons !== []) : ?>
            <div class="gratora-deact__why" role="group"
                 aria-labelledby="gratora-deact-why-title" aria-describedby="gratora-deact-why-note">
                <p class="gratora-deact__why-head">
                    <span class="gratora-deact__why-title" id="gratora-deact-why-title"><?php esc_html_e('Why are you switching it off?', 'gratora-donation-platform'); ?></span>
                    <span class="gratora-deact__why-optional"><?php esc_html_e('Optional', 'gratora-donation-platform'); ?></span>
                    <button type="button" class="button-link gratora-deact__why-clear" data-gratora-deact-clear hidden>
                        <?php esc_html_e('Clear', 'gratora-donation-platform'); ?>
                    </button>
                </p>

                <?php foreach ($reasons as $key => $reason) : ?>
                    <label class="gratora-deact__reason">
                        <input type="radio" name="gratora-deact-reason" value="<?php echo esc_attr($key); ?>"
                               data-prompt="<?php echo esc_attr($reason['prompt']); ?>">
                        <span><?php echo esc_html($reason['label']); ?></span>
                    </label>
                <?php endforeach; ?>

                <textarea class="gratora-deact__comment" id="gratora-deact-comment" rows="2" hidden
                          maxlength="<?php echo esc_attr((string) $commentLength); ?>"></textarea>

                <p class="gratora-deact__why-note" id="gratora-deact-why-note">
                    <?php esc_html_e('If you pick one, it is sent to gratora.net when you deactivate, together with what you type, the versions of Gratora, WordPress and PHP, how long ago Gratora was first switched on and how far setup got. It does not include this site\'s address or name, or anyone\'s email address.', 'gratora-donation-platform'); ?>
                    <a href="https://gratora.net/privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy', 'gratora-donation-platform'); ?></a>
                </p>

                <p class="screen-reader-text" role="status" id="gratora-deact-why-status"
                   data-sent="<?php esc_attr_e('Your answer will be sent when you deactivate.', 'gratora-donation-platform'); ?>"
                   data-unsent="<?php esc_attr_e('No answer will be sent.', 'gratora-donation-platform'); ?>"></p>
            </div>
        <?php endif; ?>

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
                    data-label-busy="<?php esc_attr_e('Deactivating…', 'gratora-donation-platform'); ?>"
                    data-label-wipe="<?php esc_attr_e('Delete everything and deactivate', 'gratora-donation-platform'); ?>">
                <?php esc_html_e('Deactivate', 'gratora-donation-platform'); ?>
            </button>
        </div>
    </div>
</div>
