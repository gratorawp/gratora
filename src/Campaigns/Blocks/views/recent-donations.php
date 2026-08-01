<?php
defined('ABSPATH') || exit;
/**
 * @var string $title
 * @var array<array{name:string, is_anonymous:bool, amount_cents:int, currency:string, time_ago:string, paid_at_iso:string, message:string}> $entries
 * @var bool   $showAmount
 * @var bool   $showTime
 * @var bool   $showMessage
 * @var string $styleVars
 */
?>
<section class="dono-block dono-block--recent-donations"
         data-block="dono/recent-donations"
        <?php echo $styleVars !== '' ? ' style="' . esc_attr($styleVars) . '"' : ''; ?>>
    <?php if ($title !== '' && ! (defined('REST_REQUEST') && REST_REQUEST)): ?>
        <h3 class="dono-block__title"><?php echo esc_html($title); ?></h3>
    <?php endif; ?>

    <?php if (! $entries): ?>
        <p class="dono-block__empty"><?php esc_html_e('No donations yet.', 'dono'); ?></p>
    <?php else: ?>
        <ul class="dono-recent-donations__list">
            <?php foreach ($entries as $entry): ?>
                <li class="dono-recent-donations__item">
                    <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="dono-recent-donations__content">
                        <div class="dono-recent-donations__header">
                            <span class="dono-recent-donations__name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                                <?php echo esc_html($entry['name']); ?>
                            </span>
                            <?php if ($showAmount): ?>
                                <span class="dono-recent-donations__amount">
                                    <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $entry['currency'], true)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($showTime || ($showMessage && $entry['message'] !== '')): ?>
                            <div class="dono-recent-donations__meta">
                                <?php if ($showTime): ?>
                                    <time class="dono-recent-donations__time"
                                          datetime="<?php echo esc_attr($entry['paid_at_iso']); ?>">
                                        <?php echo esc_html($entry['time_ago']); ?>
                                    </time>
                                <?php endif; ?>
                                <?php if ($showMessage && $entry['message'] !== ''): ?>
                                    <blockquote class="dono-recent-donations__message">
                                        <?php echo esc_html($entry['message']); ?>
                                    </blockquote>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
