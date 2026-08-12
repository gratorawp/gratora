<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorPurge;
use Dono\Donors\DonorService;
use Dono\Foundation\Plugin;

/**
 * `retention_days_after_redaction` was a dead control: it saved, round-tripped,
 * and no PHP ever read it.
 *
 * It is the grace period in which a redacted donor who gives again is still
 * recognized. Redaction keeps `email_hash` on purpose, and findOrCreate matches
 * on it to un-redact and reunite them with their history. When the window
 * closes the hash is replaced, so the same person becomes a new donor and their
 * old giving stays on an anonymous shell.
 */
final class DonorPurgeTest extends IntegrationTestCase
{
    private const EMAIL = 'returning@example.test';

    private function service(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function purger(): DonorPurge
    {
        return Plugin::instance()->container->get(DonorPurge::class);
    }

    private function window(int $days): void
    {
        update_option('dono_privacy', ['retention_days_after_redaction' => $days]);
    }

    private function redactedDonor(string $email = self::EMAIL): Donor
    {
        $donor = $this->service()->findOrCreate($email, ['first_name' => 'Vera', 'last_name' => 'Rubin']);
        $this->service()->redact($donor);
        return Donor::query()->find('id', (int) $donor->id);
    }

    private function backdateRedaction(int $donorId, int $daysAgo): void
    {
        Donor::query()
            ->where('id', $donorId)
            ->update(['redacted_at' => gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400))]);
    }

    protected function tearDown(): void
    {
        delete_option('dono_privacy');
        parent::tearDown();
    }

    /** The behaviour the window exists to protect. */
    public function test_inside_the_window_a_returning_donor_is_reunited(): void
    {
        $this->window(90);
        $donor = $this->redactedDonor();

        $this->purger()->run();

        $back = $this->service()->findOrCreate(self::EMAIL, [], true);

        $this->assertSame((int) $donor->id, (int) $back->id, 'same record, so their history follows them');
        $this->assertNull($back->redacted_at, 'giving again lifts the redaction');
    }

    public function test_past_the_window_the_same_person_becomes_a_new_donor(): void
    {
        $this->window(30);
        $donor = $this->redactedDonor();
        $this->backdateRedaction((int) $donor->id, 31);

        $this->purger()->run();

        $back = $this->service()->findOrCreate(self::EMAIL, [], true);

        $this->assertNotSame((int) $donor->id, (int) $back->id, 'the handle is gone, so this is someone new');

        $old = Donor::query()->find('id', (int) $donor->id);
        $this->assertNotNull($old->redacted_at, 'and the old shell stays redacted');
    }

    public function test_the_severed_hash_is_unique_per_row(): void
    {
        $this->window(0);

        $a = $this->redactedDonor('a-' . uniqid() . '@example.test');
        $b = $this->redactedDonor('b-' . uniqid() . '@example.test');

        // email_hash is UNIQUE, so blanking it would collide on the second row.
        $this->assertNotSame($a->email_hash, $b->email_hash);
        $this->assertSame(DonorPurge::severedHash((int) $a->id), $a->email_hash);
        $this->assertSame(DonorPurge::severedHash((int) $b->id), $b->email_hash);
    }

    public function test_a_zero_window_severs_the_handle_during_redaction(): void
    {
        $this->window(0);
        $donor = $this->redactedDonor();

        $this->assertSame(
            DonorPurge::severedHash((int) $donor->id),
            $donor->email_hash,
            'no waiting for tomorrow sweep'
        );
    }

    public function test_donation_totals_survive_the_purge(): void
    {
        $this->window(0);
        $donor = $this->service()->findOrCreate(self::EMAIL, ['first_name' => 'Vera']);
        Donor::query()->where('id', (int) $donor->id)->update([
            'total_donated_cents' => 12500,
            'donations_count'     => 3,
        ]);

        $this->service()->redact(Donor::query()->find('id', (int) $donor->id));

        $shell = Donor::query()->find('id', (int) $donor->id);
        $this->assertSame(12500, (int) $shell->total_donated_cents, 'the money stays counted');
        $this->assertSame(3, (int) $shell->donations_count);
    }

    public function test_grouping_handles_are_dropped(): void
    {
        $this->window(0);
        $donor = $this->service()->findOrCreate(self::EMAIL, ['first_name' => 'Vera']);
        Donor::query()->where('id', (int) $donor->id)->update([
            'household_id' => 4242,
            'flags'        => (string) wp_json_encode(['prefs' => ['always_anonymous' => true]]),
        ]);

        $this->service()->redact(Donor::query()->find('id', (int) $donor->id));

        $shell = Donor::query()->find('id', (int) $donor->id);
        $this->assertNull($shell->household_id, 'a household links the shell back to real people');
        $this->assertNull($shell->flags);
    }

    /**
     * The window sits outside the automatic-erasure switch on purpose: it
     * applies to every redaction, and a donor deleting their own account or an
     * admin redacting by hand both happen on a site that never switched the
     * nightly sweep on.
     */
    public function test_the_window_still_governs_a_hand_redaction_while_automatic_erasure_is_off(): void
    {
        update_option('dono_privacy', [
            'erase_inactive_donors'          => false,
            'retention_days_after_redaction' => 30,
        ]);

        $donor = $this->redactedDonor();
        $this->backdateRedaction((int) $donor->id, 31);

        $this->purger()->run();

        $this->assertNotNull(Donor::query()->find('id', (int) $donor->id)->purged_at, 'the window still closes');

        $back = $this->service()->findOrCreate(self::EMAIL, [], true);
        $this->assertNotSame((int) $donor->id, (int) $back->id, 'and the handle is gone with it');
    }

    /** Nothing here may touch a donor who was never redacted. */
    public function test_a_live_donor_is_never_purged(): void
    {
        $this->window(0);
        $live = $this->service()->findOrCreate('live@example.test', ['first_name' => 'Live']);
        $hash = $live->email_hash;

        $this->purger()->run();

        $this->assertSame($hash, Donor::query()->find('id', (int) $live->id)->email_hash);
    }

    /**
     * purge() is public and called directly from redact(), so it short-circuits
     * on an already-severed row rather than relying on the sweep's SQL to
     * filter. Proved by putting a value back that a second pass would clear:
     * asserting on updated_at would pass vacuously inside the same second.
     */
    public function test_purging_an_already_severed_row_writes_nothing(): void
    {
        $this->window(0);
        $donor = $this->redactedDonor();
        $id    = (int) $donor->id;

        Donor::query()->where('id', $id)->update(['household_id' => 777]);

        $this->purger()->purge(Donor::query()->find('id', $id));

        $this->assertSame(777, (int) Donor::query()->find('id', $id)->household_id);
    }

    /** The sweep re-runs daily; it must not churn rows it already handled. */
    public function test_the_sweep_is_idempotent(): void
    {
        $this->window(0);
        $donor = $this->redactedDonor();

        $this->purger()->run();
        $after = Donor::query()->find('id', (int) $donor->id);
        $this->purger()->run();
        $again = Donor::query()->find('id', (int) $donor->id);

        $this->assertSame($after->email_hash, $again->email_hash);
        $this->assertSame($after->updated_at, $again->updated_at, 'a second pass writes nothing');
    }
}
