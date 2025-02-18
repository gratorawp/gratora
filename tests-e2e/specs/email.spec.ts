import { test, expect } from '../fixtures/donor-form';

test.describe('email block', () => {
    test('email input renders', async ({ donor }) => {
        await expect(donor.form.locator('input[type="email"]').first()).toBeVisible();
    });

    test('invalid email blocks submission', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail('not-an-email');
        await donor.selectGateway('offline');
        await donor.submit();
        await expect(donor.successCard()).toHaveCount(0);
    });

    test('valid email reaches thank-you', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
