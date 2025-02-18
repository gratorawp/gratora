/**
 * `dono/address` block - full fieldset with inner CountrySelect.
 * Skips itself when the test form lacks the address block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('address block', () => {
    test('renders the fieldset with line1, city, postal, country', async ({ donor }) => {
        const fs = donor.addressFieldset();
        test.skip(await fs.count() === 0, 'no address block on the test form');

        await expect(fs).toBeVisible();
        await expect(fs.locator('input[autocomplete="address-line1"]').first()).toBeVisible();
        await expect(fs.locator('input[autocomplete="address-level2"]').first()).toBeVisible();
        await expect(fs.locator('input[autocomplete="postal-code"]').first()).toBeVisible();
        // The fieldset's inner country picker is the same CountrySelect
        // component as the standalone block.
        await expect(donor.countrySelect(fs)).toBeVisible();
    });

    test('country picker inside the address opens independently', async ({ donor }) => {
        const fs = donor.addressFieldset();
        test.skip(await fs.count() === 0, 'no address block on the test form');

        const inner = donor.countrySelect(fs);
        const input = inner.locator('.dono-form__country-select-input');
        await input.click();
        await input.fill('Spa');
        await expect(inner.locator('.dono-form__country-select-option').first()).toBeVisible();
        await inner.locator('.dono-form__country-select-option').filter({ hasText: 'Spain' }).first().click();
        await expect(input).toHaveValue('Spain');
    });

    test('a filled address submits cleanly to thank-you', async ({ donor }) => {
        const fs = donor.addressFieldset();
        test.skip(await fs.count() === 0, 'no address block on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.fillAddress({
            line1:   '1 Test Lane',
            city:    'Berlin',
            postal:  '10115',
            country: 'Germany',
        });
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
