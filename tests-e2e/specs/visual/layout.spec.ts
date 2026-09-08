/** Mask the goal block because functional tests change campaign totals. */

import { test, expect } from '../../fixtures/donor-form';
import { settle } from '../../helpers/visual';

const FORM_PATH = process.env.FUNDKIT_E2E_LAYOUT_FORM_PATH ?? '';

test.describe('visual: layout + content blocks', () => {
    test.skip(! FORM_PATH, 'set FUNDKIT_E2E_LAYOUT_FORM_PATH via `wp fundkit e2e-seed`');
    test.use({ formPath: FORM_PATH });

    test('initial render', async ({ donor }) => {
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('layout-form.png', {
            mask: [donor.form.locator('.fundkit-form__goal')],
        });
    });
});
