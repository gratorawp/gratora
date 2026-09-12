<?php

use Gratora\Foundation\Helpers\Money;

defined('ABSPATH') || exit;
/**
 * @var \Gratora\Donors\Donor       $donor
 * @var string                   $donor_name   name given for this donation (resolved)
 * @var \Gratora\Donations\Donation $donation
 * @var string                   $org_name
 * @var string                   $download_url
 * @var string                   $accent       the campaign's accent, print-safe
 */
$accent = (string) ($accent ?? '#211d3f');
$resolvedName = trim((string) ($donor_name ?? ''));
$donorFirst   = $resolvedName !== '' ? explode(' ', $resolvedName)[0] : '';
$amountWithCurrency = Money::format((int) $donation->amount_cents, (string) $donation->currency);

$greeting = $donorFirst !== ''
    /* translators: %s: donor's first name. */
    ? sprintf(__('Hi %s,', 'gratora-donation-platform'), $donorFirst)
    : __('Hi,', 'gratora-donation-platform');
?>
<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color:#222; line-height:1.55; max-width:580px; margin:0 auto; padding:32px 24px;">

<?php if (! empty($donation->is_test)): ?>
<p style="background:#fef2f2; border:1px solid #b91c1c; color:#b91c1c; font-weight:700; text-align:center; padding:10px; border-radius:6px; margin:0 0 20px;">
    <?php esc_html_e('Test donation. No real payment was made.', 'gratora-donation-platform'); ?>
</p>
<?php endif; ?>

<p style="font-size:16px"><?php echo esc_html($greeting); ?></p>

<p>
    <?php
    printf(
        wp_kses(
            /* translators: 1: amount with currency (e.g. "50,00 EUR"), 2: organization name */
            __('Thank you for your <strong>%1$s</strong> donation to <strong>%2$s</strong>.', 'gratora-donation-platform'),
            ['strong' => []]
        ),
        esc_html($amountWithCurrency),
        esc_html($org_name)
    ); ?>
</p>

<p>
    <?php esc_html_e(
        'Your receipt is attached as a PDF. Keep it for your records. If your local jurisdiction allows tax deductions on charitable donations, you may need it at filing time.',
        'gratora-donation-platform'
    ); ?>
</p>

<p>
    <?php esc_html_e('Reference:', 'gratora-donation-platform'); ?>
    <code style="background:#f5f3ef; padding:2px 6px; border-radius:3px"><?php echo esc_html($donation->reference); ?></code>
</p>

<p style="margin-top:24px; font-size:13px; color:#555">
    <?php esc_html_e('Lost the attachment?', 'gratora-donation-platform'); ?>
    <a href="<?php echo esc_url($download_url); ?>" style="color:<?php echo esc_attr($accent); ?>"><?php esc_html_e('Re-download your receipt', 'gratora-donation-platform'); ?></a>
    <?php esc_html_e('(link expires in 30 days).', 'gratora-donation-platform'); ?>
</p>

<p style="margin-top:32px; color:#777; font-size:13px">
    <?php esc_html_e('With gratitude,', 'gratora-donation-platform'); ?><br>
    <?php echo esc_html($org_name); ?>
</p>

</body>
</html>
