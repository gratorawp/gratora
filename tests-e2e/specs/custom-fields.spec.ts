/**
 * Custom-field blocks: `dono/text-input`, `dono/date`, `dono/number`,
 * `dono/dropdown`, `dono/radio`, `dono/checkbox`, `dono/multi-select`.
 *
 * Field names are admin-defined and the runtime emits no per-block class for
 * generic custom fields, so these specs assert presence by input type only.
 * The render-health check in the fixture catches the more important failure
 * mode (a custom-field renderer throwing inside ErrorBoundary).
 *
 * Skips each test when the matching block is absent from the test form.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('custom-field blocks', () => {
    test('a dono/date block renders a date input', async ({ donor }) => {
        const input = donor.form.locator('input[type="date"]').first();
        test.skip(await input.count() === 0, 'no date block on the test form');
        await expect(input).toBeVisible();
    });

    test('a dono/dropdown block renders a select', async ({ donor }) => {
        // Currency-switcher and gateway may also use <select>; rule them out
        // structurally.
        const selects = donor.form.locator(
            'select:not(.dono-form__currency-switcher select):not(.dono-form__gateway select)'
        );
        test.skip(await selects.count() === 0, 'no custom dropdown block on the test form');
        await expect(selects.first()).toBeVisible();
    });
});
