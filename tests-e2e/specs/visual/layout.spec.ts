/**
 * Visual regression: the layout + content form (DONO_E2E_LAYOUT_FORM_PATH).
 * Covers heading/paragraph/html/divider/columns/row/section plus the styled
 * interactive blocks (recurring-toggle, fund-picker, privacy-notice, goal).
 *
 * The goal block reads live campaign totals which drift as the functional
 * suite submits donations, so it is masked out of the comparison.
 */

import { test, expect } from '../../fixtures/donor-form';
import { settle } from '../../helpers/visual';

const FORM_PATH = process.env.DONO_E2E_LAYOUT_FORM_PATH ?? '';

test.describe('visual: layout + content blocks', () => {
    test.skip(! FORM_PATH, 'set DONO_E2E_LAYOUT_FORM_PATH via `wp dono e2e-seed`');
    test.use({ formPath: FORM_PATH });

    test('initial render', async ({ donor }) => {
        await settle(donor.page);
        await expect(donor.form).toHaveScreenshot('layout-form.png', {
            mask: [donor.form.locator('.dono-form__goal')],
        });
    });
});
