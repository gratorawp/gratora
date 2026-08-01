<?php
defined('ABSPATH') || exit;
/**
 * @var string  $title
 * @var ?string $description
 * @var ?string $imageUrl
 * @var bool    $showDescription
 * @var bool    $showCover
 * @var bool    $showSummary
 * @var bool    $showTitle
 * @var string  $raised
 * @var string  $goalLabel
 * @var int     $headingLevel
 * @var string  $align
 * @var string  $styleVars
 */
$hTag = 'h' . max(1, min(3, (int) $headingLevel));
?>
<section class="dono-block dono-block--hero is-align-<?php echo esc_attr($align); ?>" data-block="dono/campaign-hero"<?php echo $styleVars !== '' ? ' style="' . esc_attr($styleVars) . '"' : ''; ?>>
    <div class="dono-hero">
        <div class="dono-hero__cover<?php echo ($showCover && $imageUrl) ? '' : ' is-placeholder'; ?>">
            <?php if ($showCover && $imageUrl): ?>
                <img src="<?php echo esc_url($imageUrl); ?>" alt="<?php echo esc_attr($title); ?>" />
            <?php endif; ?>
            <div class="dono-hero__scrim">
                <?php if ($showTitle): ?>
                    <<?php echo esc_attr($hTag); ?> class="dono-hero__title"><?php echo esc_html($title); ?></<?php echo esc_attr($hTag); ?>>
                <?php endif; ?>
                <?php if ($showDescription && ! empty($description)): ?>
                    <p class="dono-hero__description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
                <?php if ($showSummary): ?>
                    <div class="dono-hero__summary">
                        <div class="dono-hero__raised">
                            <?php echo esc_html($raised); ?>
                            <small><?php echo esc_html($goalLabel); ?></small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
