/**
 * `gratora/consent` block - labelled consent purposes (e.g. marketing,
 * processing). Required-by-law purposes are pre-checked and locked.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('consent block', () => {
    test('renders a fieldset with at least one purpose checkbox', async ({ donor }) => {
        const fs = donor.consentFieldset();

        await expect(fs).toBeVisible();
        const checkboxes = fs.locator('input[type="checkbox"]');
        expect(await checkboxes.count(), 'consent purposes present').toBeGreaterThan(0);
    });

    test('a required purpose is the donor\'s to give, and the form insists on it', async ({ donor }) => {
        const fs = donor.consentFieldset();

        const requiredLabels = fs.locator('label:has(.gratora-form__consent-required-pill)');
        const reqCount = await requiredLabels.count();
        expect(reqCount, 'the seeded form offers a required purpose').toBeGreaterThan(0);

        // Not pre-ticked, and not disabled. A box the donor did not tick is not
        // consent - pre-ticking it is exactly what makes consent invalid - so
        // the form asks, and refuses to proceed until they answer.
        for (let i = 0; i < reqCount; i++) {
            const cb = requiredLabels.nth(i).locator('input[type="checkbox"]');
            expect(await cb.isChecked(), `required purpose #${i + 1} starts unticked`).toBe(false);
            expect(await cb.isEnabled(), `required purpose #${i + 1} is the donor's to tick`).toBe(true);
        }

        // Leaving it unticked is a refusal to submit, not a silent pass.
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Consent');
        await donor.fillEmail(`e2e+consent+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit({ consents: false });

        await donor.expectFieldError('consent');
        await expect(donor.successCard()).toBeHidden();
    });

    test('optional purposes start unchecked and toggle freely', async ({ donor }) => {
        const fs = donor.consentFieldset();

        const optional = fs.locator('label:not(:has(.gratora-form__consent-required-pill)) input[type="checkbox"]');
        const optCount = await optional.count();
        expect(optCount, 'the seeded form offers an optional purpose').toBeGreaterThan(0);

        const first = optional.first();
        const start = await first.isChecked();
        await first.click();
        expect(await first.isChecked()).toBe(! start);
    });
});
