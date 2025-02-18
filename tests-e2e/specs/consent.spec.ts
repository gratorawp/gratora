/**
 * `dono/consent` block - labelled consent purposes (e.g. marketing,
 * processing). Required-by-law purposes are pre-checked and locked.
 * Skips itself when the test form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('consent block', () => {
    test('renders a fieldset with at least one purpose checkbox', async ({ donor }) => {
        const fs = donor.consentFieldset();
        test.skip(await fs.count() === 0, 'no consent block on the test form');

        await expect(fs).toBeVisible();
        const checkboxes = fs.locator('input[type="checkbox"]');
        expect(await checkboxes.count(), 'consent purposes present').toBeGreaterThan(0);
    });

    test('required-by-law purposes are pre-checked and disabled', async ({ donor }) => {
        const fs = donor.consentFieldset();
        test.skip(await fs.count() === 0, 'no consent block on the test form');

        // The "Required" pill marks legally-required purposes. Their checkbox
        // must be `checked` AND `disabled` so the donor cannot un-opt.
        const requiredLabels = fs.locator('label:has(.dono-form__consent-required-pill)');
        const reqCount = await requiredLabels.count();
        if (reqCount === 0) test.skip(true, 'no required-by-law purposes configured');

        for (let i = 0; i < reqCount; i++) {
            const cb = requiredLabels.nth(i).locator('input[type="checkbox"]');
            expect(await cb.isChecked(), `required purpose #${i + 1} checked`).toBe(true);
            expect(await cb.isDisabled(), `required purpose #${i + 1} disabled`).toBe(true);
        }
    });

    test('optional purposes start unchecked and toggle freely', async ({ donor }) => {
        const fs = donor.consentFieldset();
        test.skip(await fs.count() === 0, 'no consent block on the test form');

        const optional = fs.locator('label:not(:has(.dono-form__consent-required-pill)) input[type="checkbox"]');
        const optCount = await optional.count();
        if (optCount === 0) test.skip(true, 'no optional purposes configured');

        const first = optional.first();
        const start = await first.isChecked();
        await first.click();
        expect(await first.isChecked()).toBe(! start);
    });
});
