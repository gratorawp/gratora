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
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes what it returns; core's own blocks print it the same way.
echo get_block_wrapper_attributes(array_filter([
    'class' => 'dono-block dono-block--top-donors dono-block--layout-' . $layout,
    'style' => $styleVars,
]));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
         data-block="dono/top-donors">
    <?php if ($title !== ''): ?>
        <h3 class="dono-block__title"><?php echo esc_html($title);
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
?>">
                    <div class="dono-top-donors__podium-rank"><?php echo esc_html((string) $rank);
?></div>
                    <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BlockAvatar::markup esc_html()s the initial and esc_url()s the image; its only other interpolation is an integer hue. ?>
                    <div class="dono-top-donors__podium-name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                        <?php echo esc_html($entry['name']);
?>
                    </div>
                    <?php if ($showAmount): ?>
                        <div class="dono-top-donors__podium-amount">
                            <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $currency, true));
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
                        <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BlockAvatar::markup esc_html()s the initial and esc_url()s the image; its only other interpolation is an integer hue. ?>
                        <span class="dono-top-donors__name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                            <?php echo esc_html($entry['name']);
?>
                        </span>
                        <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                            <span class="dono-top-donors__count">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: count */
                                    _n('(%s donation)', '(%s donations)', $entry['donations_count'], 'dono-fundraising-platform'),
                                    number_format_i18n($entry['donations_count'])
                                ));
?>
                            </span>
                        <?php endif; ?>
                        <?php if ($showAmount): ?>
                            <span class="dono-top-donors__amount">
                                <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $currency, true));
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
                    <?php echo \Dono\Campaigns\Blocks\BlockAvatar::markup($entry['name'], $entry['is_anonymous'], (string) ($entry['avatar_url'] ?? '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BlockAvatar::markup esc_html()s the initial and esc_url()s the image; its only other interpolation is an integer hue. ?>
                    <span class="dono-top-donors__name<?php echo $entry['is_anonymous'] ? ' is-anonymous' : ''; ?>">
                        <?php echo esc_html($entry['name']);
?>
                    </span>
                    <?php if ($showDonorCount && $entry['donations_count'] > 0): ?>
                        <span class="dono-top-donors__count">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: count */
                                _n('(%s donation)', '(%s donations)', $entry['donations_count'], 'dono-fundraising-platform'),
                                number_format_i18n($entry['donations_count'])
                            ));
?>
                        </span>
                    <?php endif; ?>
                    <?php if ($showAmount): ?>
                        <span class="dono-top-donors__amount">
                            <?php echo esc_html(\Dono\Foundation\Helpers\Money::format($entry['amount_cents'], $currency, true));
?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
