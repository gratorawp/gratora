/**
 * `dono/phone` block - optional donor phone input.
 * Skips itself when the test form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('phone block', () => {
    test('renders a phone input', async ({ donor }) => {
        const input = donor.form.locator('input[type="tel"], input[autocomplete="tel"], input[name="profile[phone]"]').first();
        test.skip(await input.count() === 0, 'no phone block on the test form');
        await expect(input).toBeVisible();
    });

    test('phone is included in a successful submission', async ({ donor }) => {
        const input = donor.form.locator('input[type="tel"], input[autocomplete="tel"], input[name="profile[phone]"]').first();
        test.skip(await input.count() === 0, 'no phone block on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+phone+${Date.now()}@example.com`);
        await donor.fillPhone('+44 20 7946 0958');
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
