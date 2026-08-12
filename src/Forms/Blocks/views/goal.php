<?php
defined('ABSPATH') || exit;
/**
 * @var string  $goalType    amount|donations|donors
 * @var int     $current
 * @var int     $target
 * @var int     $percent
 * @var string  $currency
 * @var int     $donorsCount
 * @var ?string $endsAt
 * @var bool    $showAmount
 * @var bool    $showDonors
 * @var bool    $showDeadline
 */
$isAmount = $goalType === 'amount';
$fmt = static function (int $v) use ($isAmount, $currency): string {
    return $isAmount
        ? \Dono\Foundation\Helpers\Money::compact($v, $currency)
        : (string) number_format_i18n($v);
};
$unitLabel = match ($goalType) {
    'donations' => _n('donation', 'donations', $target, 'dono-fundraising-platform'),
    'donors'    => _n('donor', 'donors', $target, 'dono-fundraising-platform'),
    default     => '',
};
?>
<div class="dono-block dono-block--goal dono-goal">
    <?php if ($showAmount): ?>
        <div class="dono-goal__amount">
            <strong><?php echo esc_html($fmt($current)); ?></strong>
            <span><?php
            if ($isAmount) {
                /* translators: %s formatted goal amount with currency */
                printf(esc_html__('raised of %s goal', 'dono-fundraising-platform'), esc_html($fmt($target)));
            } else {
                printf(
                    /* translators: 1: target number, 2: unit label e.g. donations */
                    esc_html__('of %1$s %2$s goal', 'dono-fundraising-platform'),
                    esc_html($fmt($target)),
                    esc_html($unitLabel)
                );
            }
            ?></span>
        </div>
    <?php endif; ?>

    <div class="dono-goal__bar" role="progressbar"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $percent); ?>">
        <div class="dono-goal__fill" style="width:<?php echo esc_attr((string) $percent); ?>%"></div>
    </div>

    <div class="dono-goal__meta">
        <?php if ($showDonors && $donorsCount > 0): ?>
            <span class="dono-goal__donors">
                <?php
                /* translators: %d number of donors */
                printf(esc_html(_n('%d donor', '%d donors', $donorsCount, 'dono-fundraising-platform')), (int) $donorsCount);
                ?>
            </span>
        <?php endif; ?>

        <?php if ($showDeadline && $endsAt): ?>
            <span class="dono-goal__deadline">
                <?php
                $days = max(0, (int) floor((strtotime((string) $endsAt) - time()) / 86400));
                /* translators: %d days remaining */
                printf(esc_html(_n('%d day left', '%d days left', $days, 'dono-fundraising-platform')), (int) $days);
                ?>
            </span>
        <?php endif; ?>
    </div>
</div>
