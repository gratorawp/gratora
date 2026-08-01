<?php
defined('ABSPATH') || exit;
/**
 * @var string $heading
 * @var array  $cards   list of ['title','blurb','imageUrl','url','raised','goalLabel','percent','accent']
 * @var string $styleVars
 * @var ?string $emptyText  set only when there are no cards
 * @var ?string $notice     editor-only explanation, empty for visitors
 */
?>
<section class="dono-block dono-block--grid" data-block="dono/campaign-grid"<?php echo $styleVars !== '' ? ' style="' . esc_attr($styleVars) . '"' : ''; ?>>
    <?php if ($heading !== ''): ?>
        <div class="dono-grid__head">
            <h2 class="dono-grid__title"><?php echo esc_html($heading); ?></h2>
        </div>
    <?php endif; ?>
    <?php if ($cards === []): ?>
        <p class="dono-block__empty"><?php echo esc_html($emptyText ?? ''); ?></p>
        <?php if (($notice ?? '') !== ''): ?>
            <div class="dono-block-notice"><?php echo esc_html($notice); ?></div>
        <?php endif; ?>
    <?php else: ?>
    <div class="dono-campaign-grid">
        <?php foreach ($cards as $card): ?>
            <a class="dono-campaign-card<?php echo $card['url'] === '' ? ' is-inert' : ''; ?>"
               <?php echo $card['url'] !== '' ? 'href="' . esc_url($card['url']) . '"' : ''; ?>
               style="--dono-accent: <?php echo esc_attr($card['accent']); ?>;">
                <span class="dono-campaign-card__cover<?php echo $card['imageUrl'] ? '' : ' is-placeholder'; ?>">
                    <?php if ($card['imageUrl']): ?>
                        <img src="<?php echo esc_url($card['imageUrl']); ?>" alt="<?php echo esc_attr($card['title']); ?>" loading="lazy" />
                    <?php endif; ?>
                </span>
                <span class="dono-campaign-card__body">
                    <span class="dono-campaign-card__title"><?php echo esc_html($card['title']); ?></span>
                    <?php if ($card['blurb'] !== ''): ?>
                        <span class="dono-campaign-card__blurb"><?php echo esc_html(wp_strip_all_tags($card['blurb'])); ?></span>
                    <?php endif; ?>
                    <span class="dono-campaign-card__progress">
                        <span class="dono-campaign-card__bar" role="progressbar" aria-valuenow="<?php echo (int) $card['percent']; ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width: <?php echo (int) $card['percent']; ?>%;"></span>
                        </span>
                        <span class="dono-campaign-card__meta">
                            <span class="dono-campaign-card__raised"><?php echo esc_html($card['raised']); ?></span>
                            <?php if ($card['goalLabel'] !== ''): ?>
                                <span class="dono-campaign-card__goal"><?php echo esc_html($card['goalLabel']); ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="dono-campaign-card__foot">
                        <?php if ($card['percent'] > 0): ?>
                            <span class="dono-campaign-card__pct"><?php echo (int) $card['percent']; ?>%</span>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <span class="dono-campaign-card__link">
                            <?php esc_html_e('Donate', 'dono'); ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </span>
                    </span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
