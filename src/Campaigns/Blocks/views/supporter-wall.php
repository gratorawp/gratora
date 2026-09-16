<?php

use Gratora\Campaigns\Blocks\BlockAvatar;
use Gratora\Foundation\Helpers\Money;

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
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--supporter-wall',
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
         data-block="gratora/supporter-wall">
    <?php if ($title !== ''): ?>
        <h3 class="gratora-block__title"><?php echo esc_html($title);
?></h3>
    <?php endif; ?>

    <?php if (! $entries): ?>
        <?php require __DIR__ . '/empty-cta.php'; ?>
    <?php else: ?>
        <ul class="gratora-supporter-wall is-cols-<?php echo esc_attr($columns);
?>">
            <?php foreach ($entries as $entry): ?>
                <li class="gratora-supporter-wall__card<?php echo esc_attr($entry['message'] !== '' && $showMessage ? ' has-message' : ''); ?>">
                    <div class="gratora-supporter-wall__top">
                        <?php BlockAvatar::render($entry['name'], false, (string) ($entry['avatar_url'] ?? '')); ?>
                        <div class="gratora-supporter-wall__name"><?php echo esc_html($entry['name']);
?></div>
                    </div>
                    <?php if ($showAmount && $entry['amount_cents'] > 0): ?>
                        <div class="gratora-supporter-wall__amount">
                            <?php echo esc_html(Money::format($entry['amount_cents'], $entry['currency'], true));
?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showMessage && $entry['message'] !== ''): ?>
                        <blockquote class="gratora-supporter-wall__message">
                            <?php echo esc_html($entry['message']);
?>
                        </blockquote>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
