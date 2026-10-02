<?php

declare(strict_types=1);

namespace Gratora\Dashboard;

use Gratora\Donations\Donation;
use Gratora\Donations\DonationQueries;
use Gratora\Foundation\Auth\Capabilities;

/**
 * Whether the dashboard asks the current user for a review on WordPress.org.
 *
 * It asks once donors have given on the site, asks only someone who manages
 * the plugin, and stops at their answer: a review or a refusal for good,
 * "later" for a month. Per user, because a review is one person's to give.
 *
 * @since 1.1.1
 */
final class ReviewPrompt
{
    /**
     * Paid donations given on the site before the question is fair to ask.
     * Recorded and imported ones aside: the sentence says they came in through
     * the plugin, and a site is not asked on the day it moves its history over.
     */
    public const AFTER_DONATIONS = 5;

    public const ANSWERS = ['reviewed', 'later', 'never'];

    private const META_KEY = 'gratora_review_prompt';

    private const LATER = 30 * DAY_IN_SECONDS;

    /** @since 1.1.1 */
    public function due(): bool
    {
        if (! Capabilities::userCan('gratora_manage_settings')) {
            return false;
        }

        $answer = (string) get_user_meta(get_current_user_id(), self::META_KEY, true);
        if ($answer === 'reviewed' || $answer === 'never') {
            return false;
        }
        if (str_starts_with($answer, 'later:') && (int) substr($answer, 6) > time()) {
            return false;
        }

        return $this->enoughGiven();
    }

    /** @since 1.1.1 */
    public function answer(string $answer): void
    {
        update_user_meta(
            get_current_user_id(),
            self::META_KEY,
            $answer === 'later' ? 'later:' . (time() + self::LATER) : $answer
        );
    }

    /**
     * Newest first and no further than the threshold: this runs on every
     * dashboard load until the person answers, and a count would read every
     * donation the site holds.
     */
    private function enoughGiven(): bool
    {
        $newest = DonationQueries::takenByThisSite(DonationQueries::donationsOnly(Donation::query()))
            ->whereIn('status', ['paid', 'partial_refund'])
            ->orderBy('id', 'DESC')
            ->limit(self::AFTER_DONATIONS)
            ->pluck('id');

        return count($newest) >= self::AFTER_DONATIONS;
    }
}
