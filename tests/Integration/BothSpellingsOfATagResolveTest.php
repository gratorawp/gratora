<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Helpers\TemplateTokens;
use Gratora\Donations\Donation;
use Gratora\Donors\Donor;
use Gratora\Foundation\Plugin;
use Gratora\Mail\Mailer;
use Gratora\Receipts\ReceiptContext;
use Gratora\Receipts\Renderers\GenericReceiptRenderer;
use Gratora\Reports\TaxStatementBuilder;
use Gratora\Settings\SettingsService;
use ReflectionMethod;

/**
 * The admin says organization in every label beside the fields these merge tags
 * go in, and the tag is {organisation_name}. The panels insert the canonical
 * spelling on a click, so what breaks is the hand-typed one.
 *
 * On a receipt it reached the donor as literal braces. On a tax statement it
 * was worse: the sweep that clears unexpanded tags, so a donor does not file a
 * document reading {amount}, deleted it outright, and the statement went out
 * with the charity's name simply missing.
 */
final class BothSpellingsOfATagResolveTest extends IntegrationTestCase
{
    public function test_the_other_spelling_resolves_to_the_same_value(): void
    {
        $map = TemplateTokens::withSpellings(['{organisation_name}' => 'Wildwater Trust']);

        $this->assertSame('Wildwater Trust', $map['{organisation_name}'] ?? null);
        $this->assertSame('Wildwater Trust', $map['{organization_name}'] ?? null);
    }

    /** The unwrapped form, for callers holding names without braces. */
    public function test_the_unwrapped_form_resolves_too(): void
    {
        $tokens = TemplateTokens::withSpellingsUnwrapped(['organisation_name' => 'Wildwater Trust']);

        $this->assertSame('Wildwater Trust', $tokens['organisation_name'] ?? null);
        $this->assertSame('Wildwater Trust', $tokens['organization_name'] ?? null);
    }

    /** A template writing both gets one value, not one filled and one bare. */
    public function test_a_template_using_both_comes_out_whole(): void
    {
        $map  = TemplateTokens::withSpellings(['{organisation_name}' => 'Wildwater Trust']);
        $said = strtr('Thank you from {organisation_name}, on behalf of {organization_name}.', $map);

        $this->assertSame('Thank you from Wildwater Trust, on behalf of Wildwater Trust.', $said);
        $this->assertStringNotContainsString('{', $said, 'nothing is left for a donor to read as a tag');
    }

    /** Nothing is invented for a tag the caller never offered. */
    public function test_a_tag_that_was_not_offered_is_not_added(): void
    {
        $map = TemplateTokens::withSpellings(['{donor_name}' => 'Sam']);

        $this->assertArrayNotHasKey('{organization_name}', $map);
        $this->assertArrayNotHasKey('{organisation_name}', $map);
    }

    /**
     * A caller that already answers the other spelling keeps its own value.
     * Overwriting it would make the alias the authority over the caller.
     */
    public function test_a_value_the_caller_set_itself_is_not_overwritten(): void
    {
        $map = TemplateTokens::withSpellings([
            '{organisation_name}' => 'British spelling',
            '{organization_name}' => 'American spelling',
        ]);

        $this->assertSame('American spelling', $map['{organization_name}']);
    }

    /** The canonical spelling is still the canonical one. */
    public function test_the_canonical_tag_is_unchanged(): void
    {
        $map = TemplateTokens::withSpellings(['{organisation_name}' => 'Wildwater Trust']);

        $this->assertArrayHasKey('{organisation_name}', $map);
    }

    // -- and the three places a person can type one ------------------------

    /**
     * Reflection on the private expanders, which is how this suite already
     * reaches the statement footer: each is the real production method, so a
     * helper that works and is wired into nothing fails here.
     */
    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }

    public function test_an_email_body_resolves_the_other_spelling(): void
    {
        $mailer = Plugin::instance()->container->get(Mailer::class);

        $said = (string) $this->invoke(
            $mailer,
            'interpolate',
            'Thank you from {organization_name}.',
            ['organisation_name' => 'Wildwater Trust', 'donor_name' => 'Sam']
        );

        $this->assertSame('Thank you from Wildwater Trust.', $said);
    }

    /**
     * The worst of the three: the statement clears tags it cannot answer, so a
     * donor filed a document with the charity's name silently missing rather
     * than with visible braces.
     */
    public function test_a_tax_statement_footer_resolves_the_other_spelling(): void
    {
        (new SettingsService())->update('receipts', ['footer_note' => 'Issued by {organization_name}.']);

        $footer = (string) $this->invoke(
            Plugin::instance()->container->get(TaxStatementBuilder::class),
            'orgDisclaimer',
            'Acme Foundation',
            'Ada Lovelace'
        );

        $this->assertSame('Issued by Acme Foundation.', $footer);
    }

    /** The one a donor keeps: it printed the braces rather than dropping them. */
    public function test_a_receipt_template_resolves_the_other_spelling(): void
    {
        $donation = Donation::make();
        $donation->reference    = 'DON-2026-000001';
        $donation->amount_cents = 5000;
        $donation->currency     = 'USD';

        $ctx = new ReceiptContext(
            $donation,
            Donor::make(),
            'en_US',
            ['name' => 'Wildwater Trust'],
            null,
            null,
            'Sam'
        );

        $out = (array) $this->invoke(
            Plugin::instance()->container->get(GenericReceiptRenderer::class),
            'expandMergeTags',
            [
                'header_title'       => 'Receipt from {organization_name}',
                'intro'              => 'Thank you, {donor_name}.',
                'signoff'            => '',
                'footer_note'        => '',
                'show_tax_id'        => false,
                'show_donor_address' => false,
                'logo_url'           => '',
                'accent_color'       => '',
                'accent_ink'         => '',
            ],
            $ctx,
            '$50.00'
        );

        $this->assertSame('Receipt from Wildwater Trust', $out['header_title']);
        $this->assertStringNotContainsString('{', (string) $out['header_title']);
    }

    public function test_the_canonical_spelling_still_works_on_a_statement(): void
    {
        (new SettingsService())->update('receipts', ['footer_note' => 'Issued by {organisation_name}.']);

        $footer = (string) $this->invoke(
            Plugin::instance()->container->get(TaxStatementBuilder::class),
            'orgDisclaimer',
            'Acme Foundation',
            'Ada Lovelace'
        );

        $this->assertSame('Issued by Acme Foundation.', $footer);
    }
}
