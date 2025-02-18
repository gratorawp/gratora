/**
 * Standalone `dono/country` block - searchable picker (CountrySelect).
 * Skips itself when the canonical form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('country block', () => {
    test('renders the searchable picker', async ({ donor }) => {
        const cs = donor.countrySelect();
        test.skip(await cs.count() === 0, 'no country picker on the test form');
        await expect(cs).toBeVisible();
        await expect(cs.locator('.dono-form__country-select-input')).toBeVisible();
    });

    test('typing filters the option list and clicking picks the country', async ({ donor }) => {
        const cs = donor.countrySelect();
        test.skip(await cs.count() === 0, 'no country picker on the test form');

        const input = cs.locator('.dono-form__country-select-input');
        await input.click();
        await input.fill('Fran');

        const options = cs.locator('.dono-form__country-select-option');
        await expect(options.first()).toBeVisible();
        // Every visible option must contain the substring (case-insensitive)
        // either in the label or in the ISO hint.
        const labels = await options.allTextContents();
        for (const t of labels) {
            expect(t.toLowerCase()).toContain('fran');
        }

        await options.filter({ hasText: 'France' }).first().click();
        await expect(input).toHaveValue('France');
    });

    test('Escape closes the list without selecting', async ({ donor }) => {
        const cs = donor.countrySelect();
        test.skip(await cs.count() === 0, 'no country picker on the test form');

        const input = cs.locator('.dono-form__country-select-input');
        await input.click();
        await input.fill('Ger');
        await expect(cs.locator('.dono-form__country-select-list')).toBeVisible();
        await input.press('Escape');
        await expect(cs.locator('.dono-form__country-select-list')).toHaveCount(0);
    });
});
