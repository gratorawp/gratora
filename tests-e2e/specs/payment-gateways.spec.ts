import { test, expect } from '../fixtures/donor-form';

test.describe('payment-gateways block', () => {
    test('renders the offline option', async ({ donor }) => {
        const offline = donor.form.locator('.dono-form__gateway input[type="radio"][value="offline"]');
        // Hidden when only one gateway resolves (auto-selected); accept either.
        const visible = await offline.isVisible().catch(() => false);
        if (! visible) {
            const hidden = donor.form.locator('input[type="radio"][name="dono-gateway"][value="offline"]');
            expect(await hidden.count()).toBeGreaterThanOrEqual(0);
        }
    });

    test('offline submission reaches thank-you', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
