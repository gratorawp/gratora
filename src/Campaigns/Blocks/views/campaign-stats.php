<?php
defined('ABSPATH') || exit;
/**
 * @var int    $raisedCents
 * @var int    $donationsCount
 * @var int    $donorsCount
 * @var string $currency
 * @var bool   $showRaised
 * @var bool   $showDonations
 * @var bool   $showDonors
 * @var string $align
 */
?>
<section class="dono-block dono-block--stats is-align-<?php echo esc_attr($align); ?>" data-block="dono/campaign-stats">
    <?php if ($showRaised): ?>
        <div class="dono-stat">
            <div class="dono-stat__value"><?php echo esc_html(\Dono\Foundation\Helpers\Money::compact($raisedCents, $currency)); ?></div>
            <div class="dono-stat__label"><?php esc_html_e('Raised', 'dono'); ?></div>
        </div>
    <?php endif; ?>
    <?php if ($showDonations): ?>
        <div class="dono-stat">
            <div class="dono-stat__value"><?php echo esc_html(number_format_i18n($donationsCount)); ?></div>
            <div class="dono-stat__label"><?php esc_html_e('Donations', 'dono'); ?></div>
        </div>
    <?php endif; ?>
    <?php if ($showDonors): ?>
        <div class="dono-stat">
            <div class="dono-stat__value"><?php echo esc_html(number_format_i18n($donorsCount)); ?></div>
            <div class="dono-stat__label"><?php esc_html_e('Donors', 'dono'); ?></div>
        </div>
    <?php endif; ?>
</section>
