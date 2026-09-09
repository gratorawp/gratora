/**
 * Custom-field blocks: `gratora/text-input`, `gratora/date`, `gratora/number`,
 * `gratora/dropdown`, `gratora/radio`, `gratora/checkbox`, `gratora/multi-select`.
 *
 * Field names are admin-defined and the runtime emits no per-block class for
 * generic custom fields, so these specs assert presence by input type only.
 * The render-health check in the fixture catches the more important failure
 * mode (a custom-field renderer throwing inside ErrorBoundary).
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('custom-field blocks', () => {
    test('a gratora/date block renders a date input', async ({ donor }) => {
        const input = donor.form.locator('input[type="date"]').first();
        await expect(input).toBeVisible();
    });

    test('a gratora/dropdown block renders a select', async ({ donor }) => {
        // Currency-switcher and gateway may also use <select>; rule them out
        // structurally.
        const selects = donor.form.locator(
            'select:not(.gratora-form__currency-switcher select):not(.gratora-form__gateway select)'
        );
        await expect(selects.first()).toBeVisible();
    });
});
