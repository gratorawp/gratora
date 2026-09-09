<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorEmailRehasher;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\Plugin;
use Gratora\Vendor\Queryable\DB;

/**
 * A rehash runs after the pepper is regenerated, so for as long as it runs
 * every returning donor misses their own row and is inserted again under the
 * new hash. When the walk reaches the original row it cannot take that hash,
 * and email_hash is unique.
 *
 * Whether the two rows can be merged is a separate question. Whether one such
 * pair stops the other 35,000 donors from ever being rehashed is not.
 */
final class DonorRehashCollisionTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        delete_option(DonorEmailRehasher::PENDING_OPTION);
        delete_option('gratora_donor_rehash_after_id');

        parent::tearDown();
    }

    private function hasher(): IdentityHasher
    {
        return Plugin::instance()->container->get(IdentityHasher::class);
    }

    private function seed(string $email, string $hash): int
    {
        $now   = gmdate('Y-m-d H:i:s');
        $donor = Donor::make();
        $donor->email_hash      = $hash;
        $donor->email_encrypted = Plugin::instance()->container->get(Crypto::class)->encrypt($email);
        $donor->created_at      = $now;
        $donor->updated_at      = $now;
        $donor->save();

        return (int) $donor->id;
    }

    private function hashOf(int $id): string
    {
        $row = DB::table('gratora_donors')->where('id', $id)->select('email_hash')->get();

        return (string) ($row['email_hash'] ?? '');
    }

    public function test_a_collision_does_not_stop_the_donors_behind_it_being_rehashed(): void
    {
        $suffix   = uniqid();
        $shared   = "shared-{$suffix}@example.test";
        $later    = "later-{$suffix}@example.test";

        // The original row, still carrying a hash made with the lost pepper.
        $original = $this->seed($shared, 'stale-' . $suffix);
        // The row a donation created mid-walk, already under the new pepper.
        $this->seed($shared, $this->hasher()->emailHash($shared));
        // And a donor the walk has not reached yet.
        $behind = $this->seed($later, 'stale-behind-' . $suffix);

        update_option('gratora_donor_rehash_after_id', (string) ($original - 1), false);
        update_option(DonorEmailRehasher::PENDING_OPTION, '1', false);

        Plugin::instance()->container->get(DonorEmailRehasher::class)->run();

        $this->assertSame(
            $this->hasher()->emailHash($later),
            $this->hashOf($behind),
            'the walk stopped at the collision and left every donor behind it unfindable by email'
        );
    }

    public function test_the_row_that_could_not_take_the_hash_keeps_the_one_it_had(): void
    {
        $suffix = uniqid();
        $shared = "keep-{$suffix}@example.test";

        $original = $this->seed($shared, 'stale-' . $suffix);
        $this->seed($shared, $this->hasher()->emailHash($shared));

        update_option('gratora_donor_rehash_after_id', (string) ($original - 1), false);
        Plugin::instance()->container->get(DonorEmailRehasher::class)->run();

        $this->assertSame('stale-' . $suffix, $this->hashOf($original));
    }

    public function test_the_collision_is_written_to_the_log(): void
    {
        $suffix = uniqid();
        $shared = "logged-{$suffix}@example.test";

        $original  = $this->seed($shared, 'stale-' . $suffix);
        $duplicate = $this->seed($shared, $this->hasher()->emailHash($shared));

        update_option('gratora_donor_rehash_after_id', (string) ($original - 1), false);
        Plugin::instance()->container->get(DonorEmailRehasher::class)->run();

        $event = DB::table('gratora_events')
            ->where('type', 'error.donor.rehash')
            ->where('donor_id', $original)
            ->orderBy('id', 'DESC')
            ->get();

        $this->assertNotNull($event, 'a duplicated donor was created and nothing said so');
        $this->assertStringContainsString(
            (string) $duplicate,
            (string) ($event['payload'] ?? ''),
            'the log has to name the row the history is split with'
        );
    }

    public function test_the_walk_finishes_rather_than_retrying_forever(): void
    {
        $suffix = uniqid();
        $shared = "finish-{$suffix}@example.test";

        $original = $this->seed($shared, 'stale-' . $suffix);
        $this->seed($shared, $this->hasher()->emailHash($shared));

        update_option('gratora_donor_rehash_after_id', (string) ($original - 1), false);
        update_option(DonorEmailRehasher::PENDING_OPTION, '1', false);

        Plugin::instance()->container->get(DonorEmailRehasher::class)->run();

        $this->assertFalse(get_option(DonorEmailRehasher::PENDING_OPTION));
    }
}
