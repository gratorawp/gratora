<?php
defined('ABSPATH') || exit;
/**
 * @var string $dismiss_url
 * @var string $admin_url
 */
?>
<div class="notice notice-info" style="position:relative; padding-right:42px">
    <h3 style="margin: .5em 0 0"><?php esc_html_e('Welcome to Dono', 'dono'); ?></h3>
    <p>
        <?php esc_html_e(
            'Donation platform with EU compliance baked in. Configure your organisation profile, gateways, and forms to start accepting donations.',
            'dono'
        ); ?>
    </p>
    <p>
        <a href="<?php echo esc_url($admin_url); ?>" class="button button-primary">
            <?php esc_html_e('Open Dono dashboard', 'dono'); ?>
        </a>
    </p>
    <a href="<?php echo esc_url($dismiss_url); ?>"
       class="notice-dismiss"
       style="text-decoration:none"
       aria-label="<?php esc_attr_e('Dismiss this notice.', 'dono'); ?>"></a>
</div>
