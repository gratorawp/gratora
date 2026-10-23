/**
 * `gratora/cover-fees` block. A check, a bank transfer and a test donation
 * carry no card fee, so the box is not offered for them and the donor pays the
 * amount chosen. The seeded form offers only those two methods.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('cover-fees block', () => {
    test('a check or a test donation is not asked to cover a fee, and gives the amount chosen', async ({ donor }) => {
        await donor.selectPresetAt(0);

        const amounts = donor.form.locator('.gratora-form__summary .gratora-form__summary-amount');
        for (const gateway of ['offline', 'sandbox']) {
            await donor.selectGateway(gateway);
            await expect(donor.coverFeesToggle()).toHaveCount(0);
            await expect(amounts.last()).toHaveText(await amounts.first().innerText());
        }

        await donor.fillName('E2E', 'Fees');
        await donor.fillEmail(`e2e+fees+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
