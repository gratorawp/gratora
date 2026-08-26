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
        ? \GiveFlow\Foundation\Helpers\Money::compact($v, $currency)
        : (string) number_format_i18n($v);
};
$unitLabel = match ($goalType) {
    'donations' => _n('donation', 'donations', $target, 'giveflow-fundraising-campaigns'),
    'donors'    => _n('donor', 'donors', $target, 'giveflow-fundraising-campaigns'),
    default     => '',
};
?>
<div class="giveflow-block giveflow-block--goal giveflow-goal">
    <?php if ($showAmount): ?>
        <div class="giveflow-goal__amount">
            <strong><?php echo esc_html($fmt($current)); ?></strong>
            <span><?php
            if ($isAmount) {
                /* translators: %s formatted goal amount with currency */
                printf(esc_html__('raised of %s goal', 'giveflow-fundraising-campaigns'), esc_html($fmt($target)));
            } else {
                printf(
                    /* translators: 1: target number, 2: unit label e.g. donations */
                    esc_html__('of %1$s %2$s goal', 'giveflow-fundraising-campaigns'),
                    esc_html($fmt($target)),
                    esc_html($unitLabel)
                );
            }
            ?></span>
        </div>
    <?php endif; ?>

    <div class="giveflow-goal__bar" role="progressbar"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $percent); ?>">
        <div class="giveflow-goal__fill" style="width:<?php echo esc_attr((string) $percent); ?>%"></div>
    </div>

    <div class="giveflow-goal__meta">
        <?php if ($showDonors && $donorsCount > 0): ?>
            <span class="giveflow-goal__donors">
                <?php
                /* translators: %d number of donors */
                printf(esc_html(_n('%d donor', '%d donors', $donorsCount, 'giveflow-fundraising-campaigns')), (int) $donorsCount);
                ?>
            </span>
        <?php endif; ?>

        <?php if ($showDeadline && $endsAt): ?>
            <span class="giveflow-goal__deadline">
                <?php
                $days = max(0, (int) floor((strtotime((string) $endsAt) - time()) / 86400));
                /* translators: %d days remaining */
                printf(esc_html(_n('%d day left', '%d days left', $days, 'giveflow-fundraising-campaigns')), (int) $days);
                ?>
            </span>
        <?php endif; ?>
    </div>
</div>
