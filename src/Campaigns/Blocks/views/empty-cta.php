<?php
defined('ABSPATH') || exit;
/**
 * The empty state for a donation list.
 *
 * These lists sit under a heading an organiser has already written, on a page
 * whose whole purpose is the ask, and a flat "No donations yet" spent that
 * space saying nothing. The first visitor to a new campaign is exactly who the
 * page most wants to reach, so the empty state carries the invitation.
 *
 * The button is omitted when the campaign is not taking donations. A campaign
 * that is a draft, finished, or not yet open has nothing to offer, and a button
 * that scrolls to a form which is not there is worse than no button.
 *
 * @var string $emptyText
 * @var string $donateLabel
 * @var string $donateUrl   empty when the campaign is not taking donations
 */
?>
<div class="dono-block__empty<?php echo $donateUrl !== '' ? ' dono-block__empty--cta' : ''; ?>">
    <p class="dono-block__empty-text"><?php echo esc_html($emptyText); ?></p>
    <?php if ($donateUrl !== ''): ?>
        <a class="dono-block__empty-btn" href="<?php echo esc_url($donateUrl); ?>">
            <?php echo esc_html($donateLabel); ?>
        </a>
    <?php endif; ?>
</div>
