<?php
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
// Core escapes these attributes; its own blocks print them the same way.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--top-donors dono-block--layout-' . $layout,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
         data-block="dono/top-donors">
    <?php if ($title !== ''): ?>
        <h3 class="dono-block__title"><?php echo esc_html($title);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
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
        <ol class="dono-top-donors__podium">
            <?php foreach ($renderOrder as [$rank, $entry]): ?>
                <li class="dono-top-donors__podium-tier dono-top-donors__podium-tier--<?php echo esc_attr((string) $rank);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>">
                    <div class="dono-top-donors__podium-rank"><?php echo esc_html((string) $rank);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?></div>
                    <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="dono-top-donors__podium-name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                        <?php echo esc_html($entry['name']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                    </div>
                    <?php if ($showAmount): ?>
                        <div class="dono-top-donors__podium-amount">
                            <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $currency, true));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                        <div class="dono-top-donors__podium-count">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: number of donations */
                                _n('%s donation', '%s donations', $entry['donations_count'], 'dono-fundraising-platform'),
                                number_format_i18n($entry['donations_count'])
                            ));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($rest): ?>
            <ol class="dono-top-donors__list" start="4">
                <?php foreach ($rest as $i => $entry): ?>
                    <li class="dono-top-donors__row">
                        <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span class="dono-top-donors__name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                            <?php echo esc_html($entry['name']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </span>
                        <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                            <span class="dono-top-donors__count">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: count */
                                    _n('(%s donation)', '(%s donations)', $entry['donations_count'], 'dono-fundraising-platform'),
                                    number_format_i18n($entry['donations_count'])
                                ));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                            </span>
                        <?php endif; ?>
                        <?php if ($showAmount): ?>
                            <span class="dono-top-donors__amount">
                                <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $currency, true));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    <?php else: ?>
        <ol class="dono-top-donors__list">
            <?php foreach ($entries as $i => $entry): ?>
                <li class="dono-top-donors__row">
                    <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span class="dono-top-donors__name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                        <?php echo esc_html($entry['name']);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                    </span>
                    <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                        <span class="dono-top-donors__count">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: count */
                                _n('(%s donation)', '(%s donations)', $entry['donations_count'], 'dono-fundraising-platform'),
                                number_format_i18n($entry['donations_count'])
                            ));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </span>
                    <?php endif; ?>
                    <?php if ($showAmount): ?>
                        <span class="dono-top-donors__amount">
                            <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $currency, true));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
