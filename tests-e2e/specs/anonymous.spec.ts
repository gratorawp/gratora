/**
 * `dono/anonymous-toggle` block - donor opts to publish anonymously.
 * Skips itself when the test form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('anonymous toggle', () => {
    test('renders a checkbox', async ({ donor }) => {
        const toggle = donor.anonymousToggle();
        test.skip(await toggle.count() === 0, 'no anonymous toggle on the test form');
        await expect(toggle).toBeVisible();
    });

    test('toggling is reflected in the input state', async ({ donor }) => {
        const toggle = donor.anonymousToggle();
        test.skip(await toggle.count() === 0, 'no anonymous toggle on the test form');

        const initial = await toggle.isChecked();
        await toggle.click();
        expect(await toggle.isChecked()).toBe(! initial);
    });

    test('an anonymous submission reaches thank-you', async ({ donor }) => {
        const toggle = donor.anonymousToggle();
        test.skip(await toggle.count() === 0, 'no anonymous toggle on the test form');

        if (! await toggle.isChecked()) await toggle.click();
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Anon');
        await donor.fillEmail(`e2e+anon+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
