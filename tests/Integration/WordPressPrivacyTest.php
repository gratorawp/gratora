<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\Donor;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\Privacy\WordPressPrivacy;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\Plugin;
use Gratora\Recurring\PlanStatus;
use Gratora\Recurring\RecurringPlan;

/**
 * Tools, Export Personal Data and Erase Personal Data are the screens a site
 * owner is pointed at when a request arrives, and what an auditor looks at.
 * They answered nothing for donors, which is not a smaller version of working.
 */
final class WordPressPrivacyTest extends IntegrationTestCase
{
    private function privacy(): WordPressPrivacy
    {
        $c = Plugin::instance()->container;

        return new WordPressPrivacy(
            $c->get(DonorRepository::class),
            $c->get(\Gratora\Donors\DonorService::class),
            $c->get(IdentityHasher::class),
            $c->get(\Gratora\Donors\DonorMetricsService::class),
            $c->get(\Gratora\Donors\ConsentService::class),
        );
    }

    private function makeDonor(string $email): Donor
    {
        $c    = Plugin::instance()->container;
        $now  = gmdate('Y-m-d H:i:s');
        $hash = $c->get(IdentityHasher::class)->emailHash($email);

        $donor = Donor::make();
        $donor->email_hash      = $hash;
        $donor->email_encrypted = $c->get(\Gratora\Foundation\Crypto\Crypto::class)->encrypt($email);
        $donor->first_name      = 'Ada';
        $donor->last_name       = 'Lovelace';
        $donor->created_at      = $now;
        $donor->updated_at      = $now;
        $donor->save();

        return $donor;
    }

    /**
     * The bundle WordPress emails a data subject under their right of access.
     * Nothing else in it is a database key, and the status was one.
     */
    public function test_a_plan_status_reaches_the_data_subject_in_words(): void
    {
        $email = 'plan-status-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);
        $now   = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'stripe';
        $plan->gateway_subscription_id = 'sub_' . uniqid();
        $plan->amount_cents            = 2000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'past_due';
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        $values = [];
        foreach ((array) ($this->privacy()->export($email)['data'] ?? []) as $group) {
            foreach ((array) ($group['data'] ?? []) as $field) {
                $values[] = (string) ($field['value'] ?? '');
            }
        }

        $this->assertContains(PlanStatus::label('past_due'), $values);
        $this->assertNotContains('past_due', $values);
    }

    public function test_wordpress_is_told_about_both_tools(): void
    {
        $this->privacy()->register();

        $this->assertArrayHasKey('gratora', apply_filters('wp_privacy_personal_data_exporters', []));
        $this->assertArrayHasKey('gratora', apply_filters('wp_privacy_personal_data_erasers', []));
    }

    public function test_an_export_returns_the_donor_the_email_belongs_to(): void
    {
        $email = 'export-' . uniqid() . '@example.test';
        $this->makeDonor($email);

        $export = $this->privacy()->export($email);

        $this->assertTrue($export['done']);
        $values = array_column($export['data'][0]['data'] ?? [], 'value');
        $this->assertContains('Ada', $values);
        $this->assertContains('Lovelace', $values);
    }

    public function test_an_unknown_email_exports_nothing(): void
    {
        $export = $this->privacy()->export('nobody-' . uniqid() . '@example.test');

        $this->assertSame([], $export['data']);
        $this->assertTrue($export['done']);
    }

    public function test_erasing_through_wordpress_redacts_the_donor(): void
    {
        $email = 'erase-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);

        $result = $this->privacy()->erase($email);

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);

        $after = (new DonorRepository())->findById((int) $donor->id);
        $this->assertNotNull($after->redacted_at, 'the donor was not actually erased');
        $this->assertNull($after->first_name);
    }

    /**
     * Donations survive an erasure and stop naming anyone, so the eraser has to
     * say something was retained. Answering "everything removed" would be a
     * false statement on a compliance screen.
     */
    public function test_it_admits_the_donations_are_kept(): void
    {
        $email = 'kept-' . uniqid() . '@example.test';
        $this->makeDonor($email);

        $result = $this->privacy()->erase($email);

        $this->assertTrue($result['items_retained']);
        $this->assertNotSame([], $result['messages']);
    }

    public function test_erasing_twice_is_not_an_error(): void
    {
        $email = 'twice-' . uniqid() . '@example.test';
        $this->makeDonor($email);

        $this->privacy()->erase($email);
        $second = $this->privacy()->erase($email);

        $this->assertTrue($second['done']);
        $this->assertFalse($second['items_removed']);
    }

    public function test_the_export_discloses_the_contact_details_the_site_holds(): void
    {
        $email = 'contact-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);
        $svc   = Plugin::instance()->container->get(\Gratora\Donors\DonorService::class);
        $svc->setEncryptedField($donor, 'phone_encrypted', '+44 20 7946 0000');
        $svc->setEncryptedField($donor, 'address_encrypted', (string) $svc->addressPayload([
            'line1'  => '12 Hill Road',
            'city'   => 'London',
            'postal' => 'NW1 6XE',
        ]));
        $donor->save();

        $values = $this->valuesIn($this->privacy()->export($email), WordPressPrivacy::GROUP);

        $this->assertContains($email, $values, 'the address the request was made from is held and was not disclosed');
        $this->assertContains('+44 20 7946 0000', $values);
        $this->assertContains("12 Hill Road\nLondon, NW1 6XE", $values);
    }

    public function test_the_export_lists_every_donation_the_donor_made(): void
    {
        $email = 'history-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);
        $this->seedDonation($donor, 'GRATORA-DSAR-A', 12_500);
        $this->seedDonation($donor, 'GRATORA-DSAR-B', 4_000);

        $values = $this->valuesIn($this->privacy()->export($email), 'gratora-donation');

        $this->assertContains('GRATORA-DSAR-A', $values, 'a donation the site holds was not in the DSAR answer');
        $this->assertContains('GRATORA-DSAR-B', $values);
    }

    public function test_the_export_lists_the_recurring_plan_still_charging_the_donor(): void
    {
        $email = 'plan-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);

        $plan = \Gratora\Recurring\RecurringPlan::make();
        $plan->donor_id                = (int) $donor->id;
        $plan->gateway                 = 'offline';
        $plan->gateway_subscription_id = 'sub_dsar_' . $donor->id;
        $plan->amount_cents            = 2_000;
        $plan->currency                = 'USD';
        $plan->interval_unit           = 'month';
        $plan->interval_count          = 1;
        $plan->status                  = 'active';
        $plan->started_at              = gmdate('Y-m-d H:i:s');
        $plan->created_at              = gmdate('Y-m-d H:i:s');
        $plan->updated_at              = gmdate('Y-m-d H:i:s');
        $plan->save();

        $groups = array_column($this->privacy()->export($email)['data'], 'group_id');

        $this->assertContains('gratora-recurring', $groups, 'an active mandate against the donor was not disclosed');
    }

    public function test_the_export_does_not_hand_over_staff_notes(): void
    {
        $email = 'notes-' . uniqid() . '@example.test';
        $donor = $this->makeDonor($email);
        Plugin::instance()->container->get(\Gratora\Donors\DonorNoteRepository::class)
            ->create((int) $donor->id, 'Never call this donor before noon.', null);

        $export = $this->privacy()->export($email);
        $all    = [];
        foreach ($export['data'] as $group) {
            $all = array_merge($all, array_column($group['data'], 'value'));
        }

        $this->assertNotContains('Never call this donor before noon.', $all);
    }

    /** @return list<string> */
    private function valuesIn(array $export, string $groupId): array
    {
        $out = [];
        foreach ($export['data'] as $group) {
            if (($group['group_id'] ?? '') !== $groupId) continue;
            $out = array_merge($out, array_column($group['data'], 'value'));
        }

        return $out;
    }

    private function seedDonation(Donor $donor, string $reference, int $cents): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $d   = \Gratora\Donations\Donation::make();
        $d->reference         = $reference;
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = $cents;
        $d->net_cents         = $cents;
        $d->currency          = 'USD';
        $d->base_amount_cents = $cents;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = $now;
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();
    }
}
