import { test, expect } from '../fixtures/donor-form';

test.describe('name block', () => {
    test('first + last inputs render', async ({ donor }) => {
        const firstInput = donor.form.locator('input[autocomplete="given-name"], input[name="profile[first_name]"]').first();
        const lastInput  = donor.form.locator('input[autocomplete="family-name"], input[name="profile[last_name]"]').first();
        await expect(firstInput).toBeVisible();
        await expect(lastInput).toBeVisible();
    });

    test('submitting without a name surfaces a field error', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectFieldError('profile.first_name');
        await expect(donor.successCard()).toHaveCount(0);
    });

    test('filled name reaches thank-you', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
