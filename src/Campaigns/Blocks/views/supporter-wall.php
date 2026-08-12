<?php
defined('ABSPATH') || exit;
/**
 * @var string $title
 * @var string $emptyText
 * @var array<array{name:string, message:string, amount_cents:int, currency:string, latest_paid_at:string}> $entries
 * @var bool   $showMessage
 * @var bool   $showAmount
 * @var string $columns
 * @var string $styleVars
 */
?>
<section <?php
// Core escapes these attributes; its own blocks print them the same way.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--supporter-wall',
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
         data-block="dono/supporter-wall">
    <?php if ($title !== ''): ?>
        <h3 class="dono-block__title"><?php echo esc_html($title);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></h3>
    <?php endif; ?>

    <?php if (! $entries): ?>
        <?php require __DIR__ . '/empty-cta.php'; ?>
    <?php else: ?>
        <ul class="dono-supporter-wall is-cols-<?php echo esc_attr($columns);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>">
            <?php foreach ($entries as $entry): ?>
                <li class="dono-supporter-wall__card<?php echo $entry['message'] !== '' && $showMessage ? ' has-message' : ''; ?>">
                    <div class="dono-supporter-wall__top">
                        <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], false, (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <div class="dono-supporter-wall__name"><?php echo esc_html($entry['name']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></div>
                    </div>
                    <?php if ($showAmount && $entry['amount_cents'] > 0): ?>
                        <div class="dono-supporter-wall__amount">
                            <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $entry['currency'], true));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showMessage && $entry['message'] !== ''): ?>
                        <blockquote class="dono-supporter-wall__message">
                            <?php echo esc_html($entry['message']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </blockquote>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
