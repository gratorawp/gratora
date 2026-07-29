/**
 * Visual regression: the canonical kitchen-sink donor form
 * (DONO_E2E_FORM_PATH, seeded by `wp dono e2e-seed`).
 *
 * Element-scoped to form.dono-donation-form so theme chrome around the
 * shortcode never bleeds into the goldens. States covered: initial render
 * (desktop + mobile), currency switched, and the field-error styling after an
 * invalid submit.
 *
 * Regenerate goldens after intentional styling changes:
 *   npm run test:visual:update
 */

import { test, expect } from '../../fixtures/donor-form';
import { settle } from '../../helpers/visual';

test.describe('visual: donor form', () => {
    test('initial render', async ({ donor }) => {
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('donor-form.png');
    });

    test('currency switched', async ({ donor }) => {
        await donor.selectCurrency('USD');
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('donor-form-usd.png');
    });

    test('field errors after invalid submit', async ({ donor }) => {
        // Amount + gateway valid, required name/email empty: the submit is
        // rejected with field-level errors and no donation is created.
        await donor.selectPresetAt(0);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectFieldError('profile.first_name');
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('donor-form-errors.png');
    });
});

test.describe('visual: donor form (mobile)', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('initial render', async ({ donor }) => {
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('donor-form-mobile.png');
    });
});
