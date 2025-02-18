/**
 * `dono/cover-fees` block - donor opts to absorb the processing fee. Toggling
 * it changes the amount transmitted on submit (base + fee).
 * Skips itself when the test form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('cover-fees block', () => {
    test('renders a checkbox', async ({ donor }) => {
        const toggle = donor.coverFeesToggle();
        test.skip(await toggle.count() === 0, 'no cover-fees block on the test form');
        await expect(toggle).toBeVisible();
    });

    test('toggling shows the fee in the label', async ({ donor }) => {
        const toggle = donor.coverFeesToggle();
        test.skip(await toggle.count() === 0, 'no cover-fees block on the test form');

        // The amount step has to have a selection so the fee can be computed.
        await donor.selectPresetAt(0);

        const label = donor.form.locator('.dono-form__cover-fees').first();
        if (! await toggle.isChecked()) await toggle.click();
        // The fee-math span renders inside an <em>; appears once a fee > 0
        // resolves from the cover-fees percent/fixed config.
        await expect(label.locator('.dono-form__cover-fees-math')).toBeVisible();
    });

    test('a fee-covered submission reaches thank-you', async ({ donor }) => {
        const toggle = donor.coverFeesToggle();
        test.skip(await toggle.count() === 0, 'no cover-fees block on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Fees');
        await donor.fillEmail(`e2e+fees+${Date.now()}@example.com`);
        if (! await toggle.isChecked()) await toggle.click();
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
