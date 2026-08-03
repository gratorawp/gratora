<?php

declare(strict_types=1);

namespace Dono\Donors\Erasure;

use Dono\Donors\Consent;
use Dono\Donors\Donor;
use Dono\Foundation\Upgrade\UpgradeRoutine;

/**
 * Clears the re-identifying hashes on consent rows of donors already erased.
 *
 * ip_hash is a salted digest over a space small enough to enumerate and
 * user_agent_hash is unsalted and so stable across installs. On a record the
 * admin screen reports as erased, both re-link it to a person.
 *
 * The consent fact, purpose and timestamp stay: they are the lawful-basis
 * evidence for everything sent before the erasure.
 *
 * @version 1.0.0
 */
final class ClearHashesOnAlreadyErasedConsents implements UpgradeRoutine
{
    /** Donors per step, not consent rows: each has few consents. */
    private const BATCH = 200;

    /**
     * Where the last step got to. The set of erased donors does not shrink as
     * this runs - clearing a donor's hashes does not un-erase them - so
     * "the first N that still need it" would clean the same batch forever and
     * then declare itself done, never reaching donor N+1.
     */
    private const CURSOR = 'dono_upgrade_clear_consent_hashes_after';

    public function id(): string
    {
        return '2026_08_clear_hashes_on_erased_consents';
    }

    public function description(): string
    {
        return __('Removing network and device fingerprints from the consent records of donors who were already erased.', 'dono');
    }

    public function step(): bool
    {
        $after = (int) get_option(self::CURSOR, 0);

        $donorIds = array_values(array_filter(array_map(
            'intval',
            (array) Donor::query()
                ->whereNotNull('redacted_at')
                ->where('id', $after, '>')
                ->orderBy('id', 'ASC')
                ->limit(self::BATCH)
                ->pluck('id')
        )));

        if ($donorIds === []) {
            delete_option(self::CURSOR);
            return true;
        }

        // Nulling a null is a no-op, so re-running a batch after a crash
        // between the work and the stamp costs nothing and changes nothing.
        Consent::query()
            ->whereIn('donor_id', $donorIds)
            ->update(['ip_hash' => null, 'user_agent_hash' => null]);

        update_option(self::CURSOR, max($donorIds), false);

        return false;
    }
}
