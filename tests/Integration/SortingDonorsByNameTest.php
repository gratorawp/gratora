<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorRepository;
use Gratora\Foundation\Plugin;

/**
 * The list drew a sort arrow on Name and served something else.
 *
 * The screen sends the field it is showing, "name". The repository's allow
 * list held "last_name", so the term matched nothing and the query fell back
 * to last_donation_at without saying so: the header claimed one order and the
 * rows were in another, and nothing on the screen or in the response gave the
 * reader a way to notice.
 */
final class SortingDonorsByNameTest extends IntegrationTestCase
{
    private function donors(): DonorRepository
    {
        return Plugin::instance()->container->get(DonorRepository::class);
    }

    private function donor(string $first, string $last, string $lastDonationAt): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donor::make();
        $d->email_hash          = hash('sha256', $first . $last . uniqid());
        $d->first_name          = $first;
        $d->last_name           = $last;
        $d->donations_count     = 1;
        $d->total_donated_cents = 1000;
        $d->last_donation_at    = $lastDonationAt;
        $d->created_at          = $now;
        $d->updated_at          = $now;
        $d->save();

        return (int) $d->id;
    }

    /** @return list<string> */
    private function surnamesInOrder(string $orderby, string $order): array
    {
        $result = $this->donors()->listAdmin([
            'orderby'  => $orderby,
            'order'    => $order,
            'per_page' => 100,
        ]);

        return array_values(array_filter(array_map(
            static fn ($d): string => (string) $d->last_name,
            $result['items']
        )));
    }

    /**
     * Seeded so the two orders disagree in both directions: by name the
     * surnames run A, M, Z and by last donation they run Z, M, A. A fallback
     * to last_donation_at cannot pass any of these by accident.
     */
    private function seedThree(): void
    {
        $this->donor('Ada', 'Andersen', '2026-03-01 00:00:00');
        $this->donor('Mia', 'Mercer', '2026-02-01 00:00:00');
        $this->donor('Zoe', 'Zeman', '2026-01-01 00:00:00');
    }

    public function test_sorting_by_name_puts_the_surnames_in_order(): void
    {
        $this->seedThree();

        $this->assertSame(
            ['Andersen', 'Mercer', 'Zeman'],
            $this->surnamesInOrder('name', 'asc')
        );
    }

    public function test_and_reverses_them(): void
    {
        $this->seedThree();

        $this->assertSame(
            ['Zeman', 'Mercer', 'Andersen'],
            $this->surnamesInOrder('name', 'desc')
        );
    }

    /** The column the repository already knew about still works. */
    public function test_last_name_still_sorts(): void
    {
        $this->seedThree();

        $this->assertSame(
            ['Andersen', 'Mercer', 'Zeman'],
            $this->surnamesInOrder('last_name', 'asc')
        );
    }

    /**
     * Anything it does not know still falls back rather than failing, because
     * a stale saved view must not take the screen down.
     */
    public function test_an_unknown_column_falls_back_to_last_donation(): void
    {
        $this->seedThree();

        $this->assertSame(
            ['Andersen', 'Mercer', 'Zeman'],
            $this->surnamesInOrder('nonsense', 'desc')
        );
    }
}
