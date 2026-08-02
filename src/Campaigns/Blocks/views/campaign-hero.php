<?php
defined('ABSPATH') || exit;
/**
 * The campaign hero, built from the shared campaign page foundation.
 *
 * It used to be a cover photo with a scrim, and the title, description and
 * money printed over it, which made the page's one call to action a caption on
 * a photograph and pushed the amount down with the image. The photo is now a
 * figure with the readout card overlapping it, so the amount, the bar and the
 * Donate button sit on a surface of their own and stay above the fold.
 * See assets/campaign-page/page.css.
 *
 * @var string  $title
 * @var ?string $imageUrl
 * @var bool    $showCover
 * @var bool    $showSummary
 * @var bool    $showTitle
 * @var bool    $showProgress
 * @var bool    $showStats
 * @var string  $raised
 * @var string  $goalLabel
 * @var bool    $hasGoal
 * @var int     $percent
 * @var int     $donorsCount
 * @var int     $donationsCount
 * @var ?int    $daysLeft
 * @var string  $donateLabel
 * @var string  $donateUrl
 * @var int     $headingLevel
 * @var string  $styleVars
 */
$hasPhoto = $showCover && ! empty($imageUrl);
$hTag     = 'h' . max(1, min(3, (int) $headingLevel));
?>
<section class="dono-block dono-block--hero dp-page" data-block="dono/campaign-hero"<?php echo $styleVars !== '' ? ' style="' . esc_attr($styleVars) . '"' : ''; ?>>
    <div class="dp-band">
        <?php if ($showTitle): ?>
            <<?php echo esc_attr($hTag); ?> class="dp-display dp-rail dp-top"><?php echo esc_html($title); ?></<?php echo esc_attr($hTag); ?>>
        <?php endif; ?>

        <div class="dp-hero<?php echo $hasPhoto ? '' : ' dp-hero--flat'; ?>">
            <div class="dp-hero-media">
                <?php if ($hasPhoto): ?>
                    <img class="dp-figure" src="<?php echo esc_url($imageUrl); ?>"
                         alt="<?php echo esc_attr($title); ?>" loading="lazy" />
                <?php endif; ?>
            </div>
            <div class="dp-hero-card">
                <?php if ($showSummary): ?>
                    <div class="dp-money">
                        <span class="dp-raised"><?php echo esc_html($raised); ?></span>
                        <span class="dp-goal"><?php echo esc_html($goalLabel); ?></span>
                        <a class="dp-btn" href="<?php echo esc_url($donateUrl); ?>">
                            <?php echo esc_html($donateLabel); ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($showProgress && $hasGoal): ?>
                    <div class="dp-bar"><i style="width: <?php echo esc_attr((string) $percent); ?>%"></i></div>
                <?php endif; ?>

                <?php if ($showStats): ?>
                    <div class="dp-stats">
                        <div class="dp-stat">
                            <b><?php echo esc_html(number_format_i18n($donorsCount)); ?></b>
                            <span><?php echo esc_html(_n('Donor', 'Donors', (int) $donorsCount, 'dono')); ?></span>
                        </div>
                        <div class="dp-stat">
                            <b><?php echo esc_html(number_format_i18n($donationsCount)); ?></b>
                            <span><?php echo esc_html(_n('Donation', 'Donations', (int) $donationsCount, 'dono')); ?></span>
                        </div>
                        <?php // Only a campaign that ends has days left to count. ?>
                        <?php if ($daysLeft !== null): ?>
                            <div class="dp-stat">
                                <b><?php echo esc_html(number_format_i18n($daysLeft)); ?></b>
                                <span><?php echo esc_html(_n('Day left', 'Days left', (int) $daysLeft, 'dono')); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
