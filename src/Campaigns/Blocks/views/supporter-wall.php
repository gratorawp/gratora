<?php
defined('ABSPATH') || exit;
/**
 * @var string $title
 * @var array<array{name:string, message:string, amount_cents:int, currency:string, latest_paid_at:string}> $entries
 * @var bool   $showMessage
 * @var bool   $showAmount
 * @var string $columns
 * @var string $themePrimary
 */
?>
<section class="dono-block dono-block--supporter-wall"
         data-block="dono/supporter-wall"
         style="--dono-accent: <?php echo esc_attr($themePrimary); ?>;">
    <?php if ($title !== '' && ! (defined('REST_REQUEST') && REST_REQUEST)): ?>
        <h3 class="dono-block__title"><?php echo esc_html($title); ?></h3>
    <?php endif; ?>

    <?php if (! $entries): ?>
        <p class="dono-block__empty"><?php esc_html_e('No supporters to show yet.', 'dono'); ?></p>
    <?php else: ?>
        <ul class="dono-supporter-wall is-cols-<?php echo esc_attr($columns); ?>">
            <?php foreach ($entries as $entry): ?>
                <li class="dono-supporter-wall__card<?php echo $entry['message'] !== '' && $showMessage ? ' has-message' : ''; ?>">
                    <div class="dono-supporter-wall__top">
                        <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <div class="dono-supporter-wall__name"><?php echo esc_html($entry['name']); ?></div>
                    </div>
                    <?php if ($showAmount && $entry['amount_cents'] > 0): ?>
                        <div class="dono-supporter-wall__amount">
                            <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $entry['currency'], true)); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showMessage && $entry['message'] !== ''): ?>
                        <blockquote class="dono-supporter-wall__message">
                            <?php echo esc_html($entry['message']); ?>
                        </blockquote>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
