/**
 * Custom-field blocks: `fundkit/text-input`, `fundkit/date`, `fundkit/number`,
 * `fundkit/dropdown`, `fundkit/radio`, `fundkit/checkbox`, `fundkit/multi-select`.
 *
 * Field names are admin-defined and the runtime emits no per-block class for
 * generic custom fields, so these specs assert presence by input type only.
 * The render-health check in the fixture catches the more important failure
 * mode (a custom-field renderer throwing inside ErrorBoundary).
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('custom-field blocks', () => {
    test('a fundkit/date block renders a date input', async ({ donor }) => {
        const input = donor.form.locator('input[type="date"]').first();
        await expect(input).toBeVisible();
    });

    test('a fundkit/dropdown block renders a select', async ({ donor }) => {
        // Currency-switcher and gateway may also use <select>; rule them out
        // structurally.
        const selects = donor.form.locator(
            'select:not(.fundkit-form__currency-switcher select):not(.fundkit-form__gateway select)'
        );
        await expect(selects.first()).toBeVisible();
    });
});
