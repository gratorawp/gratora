<?php
defined('ABSPATH') || exit;
/**
 * @var array<string,string> $reasons
 * @var bool                 $wipeOptIn
 */
?>
<div class="dono-deact" id="dono-deact" hidden>
    <div class="dono-deact__backdrop" data-dono-deact-cancel></div>
    <div class="dono-deact__panel" role="dialog" aria-modal="true" aria-labelledby="dono-deact-title">
        <h2 class="dono-deact__title" id="dono-deact-title"><?php esc_html_e('Before you go', 'dono'); ?></h2>

        <p class="dono-deact__lede">
            <?php esc_html_e('If you have a moment, what made you deactivate? Your answer is saved on this site and is not sent anywhere.', 'dono'); ?>
        </p>

        <ul class="dono-deact__reasons">
            <?php foreach ($reasons as $key => $label) : ?>
                <li>
                    <label>
                        <input type="radio" name="dono_deact_reason" value="<?php echo esc_attr($key); ?>">
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>

        <textarea
            class="dono-deact__details"
            rows="3"
            placeholder="<?php esc_attr_e('Anything you want to add', 'dono'); ?>"
        ></textarea>

        <div class="dono-deact__data">
            <label class="dono-deact__data-check">
                <input type="checkbox" id="dono-deact-wipe" <?php checked($wipeOptIn); ?>>
                <span><?php esc_html_e('Delete all Dono data as well', 'dono'); ?></span>
            </label>
            <p class="dono-deact__data-help">
                <?php esc_html_e('Leave this alone and deactivating changes nothing: your donations, donors, campaigns and settings stay exactly as they are, and switching Dono back on picks up where you left off.', 'dono'); ?>
            </p>
            <p class="dono-deact__data-warn">
                <?php esc_html_e('Tick it and every donation, donor, campaign, form and receipt is deleted the moment you deactivate. This happens straight away, it cannot be undone, and reactivating will not bring any of it back. Export anything you need first.', 'dono'); ?>
            </p>
        </div>

        <div class="dono-deact__actions">
            <button type="button" class="button-link dono-deact__skip" data-dono-deact-skip>
                <?php esc_html_e('Skip and deactivate', 'dono'); ?>
            </button>
            <span class="dono-deact__spacer"></span>
            <button type="button" class="button" data-dono-deact-cancel>
                <?php esc_html_e('Cancel', 'dono'); ?>
            </button>
            <button type="button" class="button button-primary" data-dono-deact-submit>
                <?php esc_html_e('Deactivate', 'dono'); ?>
            </button>
        </div>
    </div>
</div>
