<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Reports\TaxStatementBuilder;
use FundKit\Settings\SettingsService;

/**
 * Two ways the org's own words leaked out of a screen and into what a donor
 * receives.
 */
final class OrgIdentityAndLocaleTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function templates(): array
    {
        $email = (array) $this->settings()->get('email');

        return is_array($email['templates'] ?? null) ? $email['templates'] : [];
    }

    public function test_a_tax_statement_is_headed_with_the_legal_name_when_that_is_all_there_is(): void
    {
        // The settings screen asks for the legal name and calls the display
        // name optional, so this is a filled-in profile.
        update_option('fundkit_org_profile', ['name' => '', 'legal_name' => 'Acme Foundation e.V.']);

        $author = $this->authorOf($this->buildStatement());

        $this->assertSame('Acme Foundation e.V.', $author);
        $this->assertNotSame((string) get_bloginfo('name'), $author, 'the statement came from the WordPress site title');
    }

    public function test_a_display_name_still_heads_the_statement_when_the_org_sets_one(): void
    {
        update_option('fundkit_org_profile', ['name' => 'Acme', 'legal_name' => 'Acme Foundation e.V.']);

        $this->assertSame('Acme', $this->authorOf($this->buildStatement()));
    }

    public function test_an_org_that_filled_in_nothing_still_gets_the_site_name(): void
    {
        delete_option('fundkit_org_profile');

        $this->assertSame((string) get_bloginfo('name'), $this->authorOf($this->buildStatement()));
    }

    public function test_saving_the_emails_tab_does_not_freeze_the_default_wording(): void
    {
        // What the tab sends back: the resolved defaults, unchanged, plus the
        // one checkbox the admin actually moved.
        $current = (array) $this->settings()->get('email');

        $this->settings()->update('email', [
            'bcc_admin' => true,
            'templates' => $current['templates'],
        ]);

        $stored = get_option('fundkit_email_settings', []);
        $storedTemplates = is_array($stored['templates'] ?? null) ? $stored['templates'] : [];

        $this->assertTrue((bool) $stored['bcc_admin'], 'the setting the admin moved is saved');
        $this->assertArrayNotHasKey(
            'body',
            $storedTemplates['donation_receipt'] ?? [],
            'the default body was written into the option, pinning every donor to one locale'
        );
    }

    public function test_a_template_the_admin_actually_wrote_is_kept(): void
    {
        $current = (array) $this->settings()->get('email');
        $edited  = $current['templates'];
        $edited['donation_receipt']['body'] = 'Our own wording, {donor_first_name}.';

        $this->settings()->update('email', ['templates' => $edited]);

        $this->assertSame(
            'Our own wording, {donor_first_name}.',
            (string) ($this->templates()['donation_receipt']['body'] ?? ''),
            'an edited template has to survive the save'
        );
    }

    public function test_the_defaults_still_read_back_after_a_save_that_stored_none_of_them(): void
    {
        $current = (array) $this->settings()->get('email');
        $this->settings()->update('email', ['bcc_admin' => true, 'templates' => $current['templates']]);

        $subject = (string) ($this->templates()['donation_receipt']['subject'] ?? '');

        $this->assertNotSame('', $subject, 'dropping the stored copy must not leave the template empty');
    }

    // --- helpers ---------------------------------------------------------

    /** The org name as it reaches the document, read back out of the PDF's Info dictionary. */
    private function authorOf(string $pdf): string
    {
        if (! preg_match('/\/Author \((.*?)\)\n/s', $pdf, $m)) {
            $this->fail('the statement PDF names no author');
        }

        $raw = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $m[1]);
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        return $raw;
    }

    private function buildStatement(): string
    {
        $donors  = Plugin::instance()->container->get(DonorService::class);
        $donorId = (int) $donors->findOrCreate('statement.head@example.test', [
            'first_name' => 'Jane',
            'last_name'  => 'Donor',
        ])->id;

        $at  = '2024-03-04 09:00:00';
        $don = Donation::make();
        $don->reference         = 'FUNDKIT-STMT-' . $donorId;
        $don->donor_id          = $donorId;
        $don->amount_cents      = 10_000;
        $don->net_cents         = 10_000;
        $don->currency          = 'USD';
        $don->base_amount_cents = 10_000;
        $don->base_currency     = 'USD';
        $don->fx_rate           = '1.00000000';
        $don->gateway           = 'offline';
        $don->status            = 'paid';
        $don->is_test           = false;
        $don->paid_at           = $at;
        $don->created_at        = $at;
        $don->updated_at        = $at;
        $don->save();

        $pdf = Plugin::instance()->container->get(TaxStatementBuilder::class)
            ->build($donors->findById($donorId), 2024);

        $this->assertNotSame('', $pdf, 'precondition: the year has a deductible donation');

        return $pdf;
    }
}
