/**
 * Visual regression for the tribute field the dono-tributes add-on adds to the
 * donor form. Skips itself when that add-on is not installed on the test site.
 *
 * Regenerate goldens after intentional styling changes:
 *   npm run test:visual:update
 */

import { test, expect } from '../../fixtures/donor-form';
import { TributeField } from '../../helpers/TributeField';
import { settle } from '../../helpers/visual';

test.describe('visual: tribute field', () => {
    test('tribute expanded', async ({ donor }) => {
        const tribute = new TributeField(donor.page, donor.form);
        test.skip(await tribute.fieldset().count() === 0, 'no tribute block on the test form');

        await tribute.pick('honor');
        await tribute.fillName('Grace Hopper');
        await settle(donor.page);
        await expect(tribute.fieldset()).toHaveScreenshot('donor-form-tribute-expanded.png');
    });
});
