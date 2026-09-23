<?php

declare(strict_types=1);

namespace Gratora\Donations;

/**
 * The only value `gratora.donation.submission_proof` accepts. A listener that
 * has verified a submission by some proof other than core's own form token
 * returns an instance of this; everything else, `null` included, leaves core's
 * refusal standing.
 *
 * A class rather than a boolean because the failure modes point one way: a
 * callback that falls off the end of a branch returns `null` implicitly, and a
 * plugin returning `null` unconditionally restores core's behaviour instead of
 * disabling the site's replay control.
 *
 * @since 1.0.0
 */
final class SubmissionProof
{
}
