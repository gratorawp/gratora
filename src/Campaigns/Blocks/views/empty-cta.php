<?php
defined('ABSPATH') || exit;
/**
 * The empty state for a donation list.
 *
 * These lists sit under a heading an organizer has already written, on a page
 * whose whole purpose is the ask, so each carries the invitation rather than a
 * flat "No donations yet". Every list says its own thing, under its own icon.
 *
 * @var string $emptyText    the headline; editable per block via emptyText
 * @var string $emptySubText the softer line under it
 * @var string $emptyIcon    'donation' | 'donor' | 'supporters' | 'campaigns'
 */
?>
<div class="gratora-block__empty gratora-empty">
    <span class="gratora-empty__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <?php if (($emptyIcon ?? '') === 'donor'): ?>
                <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>
            <?php elseif (($emptyIcon ?? '') === 'campaigns'): ?>
                <rect x="3" y="4" width="7" height="7" rx="1.5"/><rect x="14" y="4" width="7" height="7" rx="1.5"/><rect x="3" y="15" width="7" height="5" rx="1.5"/><rect x="14" y="15" width="7" height="5" rx="1.5"/>
            <?php elseif (($emptyIcon ?? '') === 'supporters'): ?>
                <circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.9 3.1-7 7-7s7 3.1 7 7"/><path d="M16 3.8a3.5 3.5 0 0 1 0 6.7M18 13.4c2.4.8 4 3 4 5.6"/>
            <?php else: ?>
                <path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 0 0-7.1 7.1l1.7 1.7L12 21.5l7.1-7.1 1.7-1.7a5 5 0 0 0 0-7.1z"/>
            <?php endif; ?>
        </svg>
    </span>
    <p class="gratora-empty__title"><?php echo esc_html($emptyText); ?></p>
    <?php if (($emptySubText ?? '') !== ''): ?>
        <p class="gratora-empty__sub"><?php echo esc_html($emptySubText); ?></p>
    <?php endif; ?>
</div>
