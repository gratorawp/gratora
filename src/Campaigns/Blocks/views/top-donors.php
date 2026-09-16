<?php

use Gratora\Campaigns\Blocks\BlockAvatar;
use Gratora\Foundation\Helpers\Money;

defined('ABSPATH') || exit;
/**
 * @var string $title
 * @var string $emptyText
 * @var array<array{name:string, amount_cents:int, donations_count:int, is_anonymous:bool}> $entries
 * @var string $currency
 * @var bool   $showAmount
 * @var bool   $showDonorCount
 * @var string $layout         list|podium
 * @var string $styleVars
 */
?>
<section <?php
echo wp_kses_data(get_block_wrapper_attributes(array_filter([
    'class' => 'gratora-block gratora-block--top-donors gratora-block--layout-' . $layout,
    'style' => $styleVars,
])));
?>
         data-block="gratora/top-donors">
    <?php if ($title !== ''): ?>
        <h3 class="gratora-block__title"><?php echo esc_html($title);
?></h3>
    <?php endif; ?>

    <?php if (! $entries): ?>
        <?php require __DIR__ . '/empty-cta.php'; ?>
    <?php elseif ($layout === 'podium'): ?>
        <?php
        $podium = array_slice($entries, 0, 3);
        $rest   = array_slice($entries, 3);
        $renderOrder = [];
        if (isset($podium[1])) $renderOrder[] = [2, $podium[1]];
        if (isset($podium[0])) $renderOrder[] = [1, $podium[0]];
        if (isset($podium[2])) $renderOrder[] = [3, $podium[2]];
        ?>
        <ol class="gratora-top-donors__podium">
            <?php foreach ($renderOrder as [$rank, $entry]): ?>
                <li class="gratora-top-donors__podium-tier gratora-top-donors__podium-tier--<?php echo esc_attr((string) $rank);
?>">
                    <div class="gratora-top-donors__podium-rank"><?php echo esc_html((string) $rank);
?></div>
                    <?php BlockAvatar::render($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); ?>
                    <div class="gratora-top-donors__podium-name<?php echo esc_attr($entry['is_anonymous'] ? ' is-anonymous' : ''); ?>">
                        <?php echo esc_html($entry['name']);
?>
                    </div>
                    <?php if ($showAmount): ?>
                        <div class="gratora-top-donors__podium-amount">
                            <?php echo esc_html(Money::format($entry['amount_cents'], $currency, true));
?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                        <div class="gratora-top-donors__podium-count">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: number of donations */
                                _n('%s donation', '%s donations', $entry['donations_count'], 'gratora-donation-platform'),
                                number_format_i18n($entry['donations_count'])
                            ));
?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($rest): ?>
            <ol class="gratora-top-donors__list" start="4">
                <?php foreach ($rest as $i => $entry): ?>
                    <li class="gratora-top-donors__row">
                        <?php BlockAvatar::render($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); ?>
                        <span class="gratora-top-donors__name<?php echo esc_attr($entry['is_anonymous'] ? ' is-anonymous' : ''); ?>">
                            <?php echo esc_html($entry['name']);
?>
                        </span>
                        <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                            <span class="gratora-top-donors__count">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: count */
                                    _n('(%s donation)', '(%s donations)', $entry['donations_count'], 'gratora-donation-platform'),
                                    number_format_i18n($entry['donations_count'])
                                ));
?>
                            </span>
                        <?php endif; ?>
                        <?php if ($showAmount): ?>
                            <span class="gratora-top-donors__amount">
                                <?php echo esc_html(Money::format($entry['amount_cents'], $currency, true));
?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    <?php else: ?>
        <ol class="gratora-top-donors__list">
            <?php foreach ($entries as $i => $entry): ?>
                <li class="gratora-top-donors__row">
                    <?php BlockAvatar::render($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); ?>
                    <span class="gratora-top-donors__name<?php echo esc_attr($entry['is_anonymous'] ? ' is-anonymous' : ''); ?>">
                        <?php echo esc_html($entry['name']);
?>
                    </span>
                    <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                        <span class="gratora-top-donors__count">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: count */
                                _n('(%s donation)', '(%s donations)', $entry['donations_count'], 'gratora-donation-platform'),
                                number_format_i18n($entry['donations_count'])
                            ));
?>
                        </span>
                    <?php endif; ?>
                    <?php if ($showAmount): ?>
                        <span class="gratora-top-donors__amount">
                            <?php echo esc_html(Money::format($entry['amount_cents'], $currency, true));
?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
