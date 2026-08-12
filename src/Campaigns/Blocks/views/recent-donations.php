<?php
defined('ABSPATH') || exit;
/**
 * @var string $title
 * @var string $emptyText
 * @var array<array{name:string, is_anonymous:bool, amount_cents:int, currency:string, time_ago:string, paid_at_iso:string, message:string}> $entries
 * @var bool   $showAmount
 * @var bool   $showTime
 * @var bool   $showMessage
 * @var string $styleVars
 */
?>
<section <?php
// Core escapes these attributes; its own blocks print them the same way.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--recent-donations',
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
         data-block="dono/recent-donations">
    <?php if ($title !== ''): ?>
        <h3 class="dono-block__title"><?php echo esc_html($title);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></h3>
    <?php endif; ?>

    <?php if (! $entries): ?>
        <?php require __DIR__ . '/empty-cta.php'; ?>
    <?php else: ?>
        <ul class="dono-recent-donations__list">
            <?php foreach ($entries as $entry): ?>
                <li class="dono-recent-donations__item">
                    <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="dono-recent-donations__content">
                        <div class="dono-recent-donations__header">
                            <span class="dono-recent-donations__name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                                <?php echo esc_html($entry['name']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                            </span>
                            <?php if ($showAmount): ?>
                                <span class="dono-recent-donations__amount">
                                    <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $entry['currency'], true));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($showTime || ($showMessage && $entry['message'] !== '')): ?>
                            <div class="dono-recent-donations__meta">
                                <?php if ($showTime): ?>
                                    <time class="dono-recent-donations__time"
                                          datetime="<?php echo esc_attr($entry['paid_at_iso']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>">
                                        <?php echo esc_html($entry['time_ago']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                                    </time>
                                <?php endif; ?>
                                <?php if ($showMessage && $entry['message'] !== ''): ?>
                                    <blockquote class="dono-recent-donations__message">
                                        <?php echo esc_html($entry['message']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
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
