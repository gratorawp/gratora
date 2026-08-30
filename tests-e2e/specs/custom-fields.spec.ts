/**
 * Custom-field blocks: `fundkit/text-input`, `fundkit/date`, `fundkit/number`,
 * `fundkit/dropdown`, `fundkit/radio`, `fundkit/checkbox`, `fundkit/multi-select`.
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
    test('a fundkit/date block renders a date input', async ({ donor }) => {
        const input = donor.form.locator('input[type="date"]').first();
        test.skip(await input.count() === 0, 'no date block on the test form');
        await expect(input).toBeVisible();
    });

    test('a fundkit/dropdown block renders a select', async ({ donor }) => {
        // Currency-switcher and gateway may also use <select>; rule them out
        // structurally.
        const selects = donor.form.locator(
            'select:not(.fundkit-form__currency-switcher select):not(.fundkit-form__gateway select)'
        );
        test.skip(await selects.count() === 0, 'no custom dropdown block on the test form');
        await expect(selects.first()).toBeVisible();
    });
});
