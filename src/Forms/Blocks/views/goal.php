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
        ? \Gratora\Foundation\Helpers\Money::compact($v, $currency)
        : (string) number_format_i18n($v);
};
$unitLabel = match ($goalType) {
    'donations' => _n('donation', 'donations', $target, 'gratora'),
    'donors'    => _n('donor', 'donors', $target, 'gratora'),
    default     => '',
};
?>
<div class="gratora-block gratora-block--goal gratora-goal">
    <?php if ($showAmount): ?>
        <div class="gratora-goal__amount">
            <strong><?php echo esc_html($fmt($current)); ?></strong>
            <span><?php
            if ($isAmount) {
                /* translators: %s formatted goal amount with currency */
                printf(esc_html__('raised of %s goal', 'gratora'), esc_html($fmt($target)));
            } else {
                printf(
                    /* translators: 1: target number, 2: unit label e.g. donations */
                    esc_html__('of %1$s %2$s goal', 'gratora'),
                    esc_html($fmt($target)),
                    esc_html($unitLabel)
                );
            }
            ?></span>
        </div>
    <?php endif; ?>

    <div class="gratora-goal__bar" role="progressbar"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $percent); ?>">
        <div class="gratora-goal__fill" style="width:<?php echo esc_attr((string) $percent); ?>%"></div>
    </div>

    <div class="gratora-goal__meta">
        <?php if ($showDonors && $donorsCount > 0): ?>
            <span class="gratora-goal__donors">
                <?php
                /* translators: %d number of donors */
                printf(esc_html(_n('%d donor', '%d donors', $donorsCount, 'gratora')), (int) $donorsCount);
                ?>
            </span>
        <?php endif; ?>

        <?php if ($showDeadline && $endsAt): ?>
            <span class="gratora-goal__deadline">
                <?php
                $days = max(0, (int) floor((strtotime((string) $endsAt) - time()) / 86400));
                /* translators: %d days remaining */
                printf(esc_html(_n('%d day left', '%d days left', $days, 'gratora')), (int) $days);
                ?>
            </span>
        <?php endif; ?>
    </div>
</div>
