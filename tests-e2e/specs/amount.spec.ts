import { test, expect } from '../fixtures/donor-form';

test.describe('donation-amount block', () => {
    test('renders preset tiles', async ({ donor }) => {
        await expect(donor.presets().first()).toBeVisible();
        const count = await donor.presets().count();
        expect(count).toBeGreaterThan(0);
    });

    test('selecting a preset and submitting reaches thank-you', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('custom amount accepts a numeric value and submits', async ({ donor }) => {
        await donor.fillCustomAmount(42);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
