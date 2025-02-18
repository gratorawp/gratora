import { test, expect } from '../fixtures/donor-form';

test.describe('currency-switcher block', () => {
    test('switcher renders when the form offers >1 currency', async ({ donor }) => {
        await expect(donor.currencySwitcher()).toBeVisible();
    });

    test('changing currency updates preset tile amounts', async ({ donor }) => {
        const firstPreset = donor.presets().first();
        const before = (await firstPreset.locator('.dono-form__preset-amount').textContent()) ?? '';

        // Switch to a different currency from whatever the form opened with.
        const select = donor.currencySwitcher().locator('select');
        if (await select.count() > 0) {
            const options = await select.locator('option').all();
            for (const opt of options) {
                const value = await opt.getAttribute('value');
                if (value && value !== await select.inputValue()) {
                    await select.selectOption(value);
                    break;
                }
            }
        } else {
            const pills = donor.currencySwitcher().locator('.dono-form__currency-pill');
            const count = await pills.count();
            for (let i = 0; i < count; i++) {
                const pill = pills.nth(i);
                const aria = await pill.getAttribute('aria-pressed');
                if (aria !== 'true') {
                    await pill.click();
                    break;
                }
            }
        }

        const after = (await firstPreset.locator('.dono-form__preset-amount').textContent()) ?? '';
        expect(after).not.toBe(before);
    });

    test('submission still reaches thank-you after switching currency', async ({ donor }) => {
        const select = donor.currencySwitcher().locator('select');
        if (await select.count() > 0) {
            const opts = await select.locator('option').all();
            for (const opt of opts) {
                const value = await opt.getAttribute('value');
                if (value && value !== await select.inputValue()) {
                    await select.selectOption(value);
                    break;
                }
            }
        }
        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
