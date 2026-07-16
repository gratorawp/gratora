/**
 * Visual regression: the multi-step wizard (DONO_E2E_MULTI_STEP_FORM_PATH).
 * One golden per seeded step, so the step indicator, per-step layout, and
 * Back/Next button row are all covered:
 *   step 1: donation-amount
 *   step 2: name + email
 *   step 3: payment-gateways + submit-button
 */

import { test, expect } from '../../fixtures/donor-form';
import { settle } from '../../helpers/visual';

const MULTI_STEP_FORM_PATH = process.env.DONO_E2E_MULTI_STEP_FORM_PATH ?? '';

test.describe('visual: multi-step wizard', () => {
    test.skip(! MULTI_STEP_FORM_PATH, 'set DONO_E2E_MULTI_STEP_FORM_PATH via `wp dono e2e-seed`');
    test.use({ formPath: MULTI_STEP_FORM_PATH });

    test('each step renders', async ({ donor }) => {
        const next = donor.form.locator('.dono-form__button--primary');

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
