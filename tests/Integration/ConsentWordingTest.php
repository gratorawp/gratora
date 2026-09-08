<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Consent;
use FundKit\Donors\ConsentService;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Settings\SettingsService;

/**
 * A consent row is the record of what somebody agreed to. The registry entry
 * it names can be edited afterwards, so a row that keeps only the key appears
 * to be consent to whatever the wording says today.
 */
final class ConsentWordingTest extends IntegrationTestCase
{
    private function consents(): ConsentService
    {
        return Plugin::instance()->container->get(ConsentService::class);
    }

    private function registerPurpose(string $label, string $description): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('consents', [
            'purposes' => [[
                'key'         => 'newsletter',
                'label'       => $label,
                'description' => $description,
                'required'    => false,
                'default'     => false,
                'version'     => 1,
            ]],
        ]);
    }

    private function donorId(): int
    {
        return (int) Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('consent-' . uniqid() . '@example.test')->id;
    }

    public function test_the_row_keeps_the_wording_the_donor_read(): void
    {
        $this->registerPurpose('Email me updates', 'About once a month, never shared.');

        $row = $this->consents()->record($this->donorId(), 'newsletter', true);

        $this->assertSame('Email me updates', $row->purpose_label);
        $this->assertSame('About once a month, never shared.', $row->purpose_description);
    }

    public function test_editing_the_purpose_does_not_rewrite_what_was_agreed_to(): void
    {
        $this->registerPurpose('Email me updates', 'About once a month, never shared.');

        $row = $this->consents()->record($this->donorId(), 'newsletter', true);

        $this->registerPurpose('Marketing', 'We may share your details with partners.');

        $reread = Consent::query()->find('id', (int) $row->id);

        $this->assertSame('Email me updates', $reread->purpose_label);
        $this->assertStringNotContainsString('partners', (string) $reread->purpose_description);
    }

    public function test_a_caller_can_name_the_wording_itself(): void
    {
        $row = $this->consents()->record($this->donorId(), 'terms', true, [
            'label'       => 'I agree to the terms',
            'description' => 'Version of 1 January 2026.',
        ]);

        $this->assertSame('I agree to the terms', $row->purpose_label);
        $this->assertSame('Version of 1 January 2026.', $row->purpose_description);
    }

    public function test_a_purpose_with_no_wording_records_none_rather_than_an_empty_string(): void
    {
        $row = $this->consents()->record($this->donorId(), 'nothing-registered', true);

        $this->assertNull($row->purpose_label);
        $this->assertNull($row->purpose_description);
    }

    public function test_the_wording_travels_into_the_donor_history(): void
    {
        $this->registerPurpose('Email me updates', 'About once a month.');

        $donorId = $this->donorId();
        $this->consents()->record($donorId, 'newsletter', true);

        $profile = Plugin::instance()->container
            ->get(\FundKit\Donors\DonorMetricsService::class)
            ->profile($donorId);

        $history = $profile['consents']['history'] ?? [];
        $this->assertNotSame([], $history);
        $this->assertSame('Email me updates', $history[0]['purpose_label'] ?? null);
    }
}
