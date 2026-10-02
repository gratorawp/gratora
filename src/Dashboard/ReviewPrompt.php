<?php

declare(strict_types=1);

namespace Gratora\Dashboard;

use Gratora\Donations\Donation;
use Gratora\Foundation\Auth\Capabilities;

/**
 * Whether the dashboard asks the current user for a review on WordPress.org.
 *
 * It asks once real money has come in, asks only someone who manages the
 * plugin, and stops at their answer: a review or a refusal for good, "later"
 * for a month. Per user, because a review is one person's to give.
 *
 * @since unreleased
 */
final class ReviewPrompt
{
    /** Paid donations, test ones aside, before the question is fair to ask. */
    public const AFTER_DONATIONS = 5;

    public const ANSWERS = ['reviewed', 'later', 'never'];

    private const META_KEY = 'gratora_review_prompt';

    private const LATER = 30 * DAY_IN_SECONDS;

    /** @since unreleased */
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

        return $this->paidDonations() >= self::AFTER_DONATIONS;
    }

    /** @since unreleased */
    public function answer(string $answer): void
    {
        update_user_meta(
            get_current_user_id(),
            self::META_KEY,
            $answer === 'later' ? 'later:' . (time() + self::LATER) : $answer
        );
    }

    private function paidDonations(): int
    {
        return (int) Donation::query()
            ->where('is_test', 0)
            ->where('kind', 'donation')
            ->whereIn('status', ['paid', 'partial_refund'])
            ->count();
    }
}
