<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\FormTemplates;

/**
 * Shipped template copy is donor-facing verbatim, sitting next to amounts the
 * form renders in the org's own currency and next to the frequency the donor
 * picked. Copy that names a currency or a cadence contradicts the form it is on
 * the moment the org is not a dollar org taking monthly donations.
 *
 * @since 1.0.0
 */
final class FormTemplateCopyTest extends IntegrationTestCase
{
    private const CURRENCY_SYMBOLS = ['$', '€', '£', '¥', '₹'];

    public function test_no_template_names_a_currency_the_org_may_not_use(): void
    {
        foreach (FormTemplates::all() as $template) {
            foreach (self::CURRENCY_SYMBOLS as $symbol) {
                // Attribute text is JSON-encoded in the markup, so search for the
                // symbol in the same encoding the templates are built with.
                $needle = trim((string) wp_json_encode($symbol), '"');

                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    (string) $template['blocks'],
                    "template {$template['id']}: copy hardcodes {$symbol}, which contradicts the amount the form renders above it"
                );
            }
        }
    }

    /**
     * The sustainer offers yearly too, and the shortcode prepends a one-time
     * pill, so the sentence beside the button cannot assert a monthly charge.
     */
    public function test_the_sustainer_does_not_promise_a_cadence_the_donor_did_not_pick(): void
    {
        $template = FormTemplates::find('monthly-sustainer');
        $this->assertNotNull($template);

        $this->assertContains('yearly', (array) ($template['settings']['recurring']['frequencies'] ?? []));
        $this->assertDoesNotMatchRegularExpression(
            '/\b(each|a) month\b/i',
            (string) $template['blocks'],
            'the sustainer states a monthly cadence on a form that also takes yearly and one-time donations'
        );
    }
}
