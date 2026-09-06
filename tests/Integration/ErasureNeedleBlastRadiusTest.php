<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Analytics\Event;
use FundKit\Donations\Donation;
use FundKit\Donors\DonorService;
use FundKit\Donors\Erasure\ErasureRequest;
use FundKit\Foundation\Plugin;

/**
 * Erasing one donor must not blank another donor's rows.
 *
 * Needles are searched as LIKE '%needle%' over the payload, and the handlers do
 * not redact what they match, they null it. A four-character minimum is fine
 * for a value that is unique by construction and nowhere near enough for a
 * name: erasing "Anna Bell" reaches "Annabelle" and "Bellevue" wherever they
 * appear. The payload column collates binary, so the match is case-sensitive
 * and lowercase near-misses like joanna@ are safe - which narrows the blast
 * radius without closing it, since a name in a payload is normally capitalised
 * exactly the way the donor's own name is. DonorRetention runs erasure on a
 * schedule, so this ran unattended.
 */
final class ErasureNeedleBlastRadiusTest extends IntegrationTestCase
{
    public function test_a_bare_first_or_last_name_is_not_searched_for(): void
    {
        $request = ErasureRequest::make(
            1,
            [],
            ['anna.bell@example.com'],
            ['Anna', 'Bell', 'Anna Bell'],
            gmdate('Y-m-d H:i:s')
        );

        $this->assertContains('anna.bell@example.com', $request->needles);
        $this->assertContains('Anna Bell', $request->needles, 'the full name is distinctive enough to keep');
        $this->assertNotContains('Anna', $request->needles);
        $this->assertNotContains('Bell', $request->needles);
    }

    public function test_a_short_full_name_is_not_searched_for_either(): void
    {
        // "Li Wu" is two parts and still far too short to mean only one person
        // inside a longtext payload.
        $request = ErasureRequest::make(1, [], [], ['Li Wu'], gmdate('Y-m-d H:i:s'));

        $this->assertSame([], $request->needles);
    }

    public function test_identifiers_are_still_trusted_at_four_characters(): void
    {
        $request = ErasureRequest::make(
            1,
            [],
            ['FUNDKIT-2026-00700', 'pi_3abc', 'sub_9xy'],
            [],
            gmdate('Y-m-d H:i:s')
        );

        $this->assertContains('FUNDKIT-2026-00700', $request->needles);
        $this->assertContains('pi_3abc', $request->needles);
        $this->assertContains('sub_9xy', $request->needles);
    }

    /**
     * The gateway ids were kept or dropped by whether the donation's reference
     * was prefix-unique. A gateway's own id is opaque and has no such
     * neighbour, so a donor whose reference happened to sit inside another's
     * lost their gateway ids from the search and kept the rows carrying them.
     */
    public function test_an_opaque_gateway_id_is_erased_even_when_the_reference_is_not_unique(): void
    {
        $service = Plugin::instance()->container->get(DonorService::class);

        $target = $service->findOrCreate('prefix.target@example.com', ['first_name' => 'Target']);
        $this->donation((int) $target->id, 'DON-1', 'pi_opaquetarget');

        $other = $service->findOrCreate('prefix.other@example.com', ['first_name' => 'Other']);
        $this->donation((int) $other->id, 'DON-10', 'pi_somethingelse');

        $event = $this->event(['note' => 'charged pi_opaquetarget']);

        $service->redact($target);

        $this->assertNull(
            Event::query()->where('id', (int) $event->id)->get()->payload,
            'the row carrying the erased donor gateway id was reached'
        );
    }

    /** And a gateway id that IS extended by another donation's is still left alone. */
    public function test_a_gateway_id_another_donation_extends_is_not_searched_for(): void
    {
        $service = Plugin::instance()->container->get(DonorService::class);

        $target = $service->findOrCreate('short.id@example.com', ['first_name' => 'Shorty']);
        $this->donation((int) $target->id, 'REF-UNIQUE-A', 'pi_777');

        $other = $service->findOrCreate('longer.id@example.com', ['first_name' => 'Longy']);
        $this->donation((int) $other->id, 'REF-UNIQUE-B', 'pi_7777');

        $event = $this->event(['note' => 'charged pi_7777']);

        $service->redact($target);

        $this->assertNotNull(
            Event::query()->where('id', (int) $event->id)->get()->payload,
            'the bystander row was left alone'
        );
    }

    private function donation(int $donorId, string $reference, string $intentId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $d = Donation::make();
        $d->donor_id          = $donorId;
        $d->reference         = $reference;
        $d->gateway_intent_id = $intentId;
        $d->amount_cents      = 5000;
        $d->currency          = 'USD';
        $d->status            = 'paid';
        $d->gateway           = 'offline';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }

    /** @param array<string,mixed> $payload */
    private function event(array $payload): Event
    {
        $event = Event::make();
        $event->type        = 'form.viewed';
        $event->payload     = $payload;
        $event->occurred_at = gmdate('Y-m-d H:i:s');
        $event->save();

        return $event;
    }

    public function test_erasing_one_donor_leaves_another_donors_events_intact(): void
    {
        $service = Plugin::instance()->container->get(DonorService::class);

        $target = $service->findOrCreate('anna.bell@example.com', [
            'first_name' => 'Anna',
            'last_name'  => 'Bell',
        ]);

        // A different person entirely. "Annabelle" contains "Anna" with the same
        // capitalisation, which is all the LIKE needs.
        $event = Event::make();
        $event->type       = 'form.viewed';
        $event->payload    = ['name' => 'Annabelle Fischer', 'note' => 'form viewed'];
        $event->occurred_at = gmdate('Y-m-d H:i:s');
        $event->save();

        $service->redact($target);

        $reloaded = Event::query()->where('id', (int) $event->id)->get();

        $this->assertNotNull($reloaded, 'the bystander row still exists');
        $this->assertNotNull($reloaded->payload, 'and its payload was not blanked');
        $this->assertSame(
            'Annabelle Fischer',
            $reloaded->payload['name'] ?? null,
            'Annabelle keeps her own analytics history when Anna Bell is erased'
        );
    }
}
