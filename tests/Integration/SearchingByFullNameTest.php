<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;

/**
 * The donations list prints a donor as "Jonas Petrov", and typing that back
 * into the search box is the first thing anyone does with it.
 *
 * The term was matched against first_name and last_name separately, so no
 * field ever held both words and the screen answered with "Nothing matches
 * these filters", which reads as this donor having no donations at all.
 */
final class SearchingByFullNameTest extends IntegrationTestCase
{
    private function donors(): DonorService
    {
        return Plugin::instance()->container->get(DonorService::class);
    }

    private function donor(string $first, string $last): int
    {
        return (int) $this->donors()->findOrCreate(
            strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
            ['first_name' => $first, 'last_name' => $last]
        )->id;
    }

    /** @return list<int> */
    private function search(string $term): array
    {
        return $this->donors()->findIdsBySearch($term);
    }

    public function test_a_full_name_finds_the_donor(): void
    {
        $id = $this->donor('Jonas', 'Petrov');

        $this->assertContains($id, $this->search('Jonas Petrov'));
    }

    /** Said the other way round, which is how half the world writes it. */
    public function test_the_name_reversed_finds_them_too(): void
    {
        $id = $this->donor('Jonas', 'Petrov');

        $this->assertContains($id, $this->search('Petrov Jonas'));
    }

    public function test_either_name_on_its_own_still_finds_them(): void
    {
        $id = $this->donor('Jonas', 'Petrov');

        $this->assertContains($id, $this->search('Jonas'));
        $this->assertContains($id, $this->search('Petrov'));
    }

    /**
     * Both words have to land. Matching any of them would turn a full name
     * into a broader search than either half, which is the opposite of what
     * typing more is for.
     */
    public function test_a_full_name_does_not_match_a_different_person(): void
    {
        $this->donor('Jonas', 'Petrov');
        $other = $this->donor('Ada', 'Petrov');

        $this->assertNotContains($other, $this->search('Jonas Petrov'));
    }

    public function test_a_surname_shared_by_two_people_finds_both(): void
    {
        $one = $this->donor('Jonas', 'Petrov');
        $two = $this->donor('Ada', 'Petrov');

        $found = $this->search('Petrov');
        $this->assertContains($one, $found);
        $this->assertContains($two, $found);
    }

    /** A middle name typed along with the rest is still the same person. */
    public function test_extra_whitespace_is_not_a_different_name(): void
    {
        $id = $this->donor('Jonas', 'Petrov');

        $this->assertContains($id, $this->search('  Jonas   Petrov  '));
    }

    /** The exact-email and id routes are untouched by any of this. */
    public function test_an_exact_email_still_finds_the_donor(): void
    {
        $email = 'exact-' . uniqid() . '@example.test';
        $id    = (int) $this->donors()->findOrCreate($email, ['first_name' => 'Ada'])->id;

        $this->assertContains($id, $this->search($email));
    }
}
