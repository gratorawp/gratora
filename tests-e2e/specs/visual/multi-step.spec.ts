
import { test, expect } from '../../fixtures/donor-form';
import { settle } from '../../helpers/visual';

const MULTI_STEP_FORM_PATH = process.env.GRATORA_E2E_MULTI_STEP_FORM_PATH ?? '';

test.describe('visual: multi-step wizard', () => {
    test.skip(! MULTI_STEP_FORM_PATH, 'set GRATORA_E2E_MULTI_STEP_FORM_PATH via `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`');
    test.use({ formPath: MULTI_STEP_FORM_PATH });

    test('each step renders', async ({ donor }) => {
        const next = donor.form.locator('.gratora-form__button--primary');

        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('wizard-step-1-amount.png');

        await donor.selectPresetAt(0);
        await next.click();
        await expect(donor.form.locator('[data-step="donor"]')).toBeVisible();
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('wizard-step-2-donor.png');

        await donor.fillName('Visual', 'Regression');
        await donor.fillEmail('vrt@example.com');
        await next.click();
        await expect(donor.gatewayOptions().first()).toBeVisible();
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('wizard-step-3-payment.png');
    });
});
