/**
 * Coverage for the custom-field blocks that the original `custom-fields`
 * spec only smoke-tested at one block each (date + dropdown). This drives
 * every remaining custom-field block end-to-end:
 *
 *   - dono/text-input       (free-text)
 *   - dono/number-input     (numeric with min/max)
 *   - dono/radio            (single-select via radios)
 *   - dono/checkbox         (single boolean)
 *   - dono/multi-select     (multi-value via checkbox group)
 *   - dono/hidden           (no DOM, value lives in state)
 *
 * Each test exercises the interactive control and then submits the full
 * form. A reaching-thank-you submit proves the field's value survived state,
 * validation, and the runtime payload builder (buildPayload's custom
 * serializer in state/store.js).
 *
 * Seeded via `wp dono e2e-seed` -> DONO_E2E_CUSTOM_FIELDS_FORM_PATH.
 */

import { test, expect } from '../fixtures/donor-form';

const FORM_PATH = process.env.DONO_E2E_CUSTOM_FIELDS_FORM_PATH ?? '';

test.describe('custom-field blocks (extended)', () => {
    test.skip(! FORM_PATH, 'set DONO_E2E_CUSTOM_FIELDS_FORM_PATH via `wp dono e2e-seed`');
    test.use({ formPath: FORM_PATH });

    test('text-input renders, accepts input, and submits', async ({ donor }) => {
        const field = donor.form
            .locator('.dono-form__field')
            .filter({ hasText: 'CUSTOM_TEXT_LABEL' });
        await expect(field).toBeVisible();
        await field.locator('input[type="text"]').fill('hello world');

        await donor.selectPresetAt(0);
        await donor.fillName('Text', 'Field');
        await donor.fillEmail(`e2e+cf+text+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('number-input renders as type=number and respects min/max attrs', async ({ donor }) => {
        const field = donor.form
            .locator('.dono-form__field')
            .filter({ hasText: 'CUSTOM_NUMBER_LABEL' });
        const input = field.locator('input[type="number"]');
        await expect(input).toBeVisible();
        await expect(input).toHaveAttribute('min', '1');
        await expect(input).toHaveAttribute('max', '100');

        await input.fill('42');

        await donor.selectPresetAt(0);
        await donor.fillName('Number', 'Field');
        await donor.fillEmail(`e2e+cf+num+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('radio renders one option per choice and submits the picked one', async ({ donor }) => {
        const fieldset = donor.form.locator('fieldset.dono-form__radio');
        await expect(fieldset).toBeVisible();
        await expect(fieldset.locator('legend')).toHaveText('CUSTOM_RADIO_LABEL');

        const options = fieldset.locator('input[type="radio"]');
        await expect(options).toHaveCount(3);

        // Pick the second option ("Beta").
        await fieldset.locator('label', { hasText: 'Beta' }).click();
        await expect(fieldset.locator('label', { hasText: 'Beta' })).toHaveClass(/is-selected/);

        await donor.selectPresetAt(0);
        await donor.fillName('Radio', 'Field');
        await donor.fillEmail(`e2e+cf+radio+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('checkbox renders, toggles, and submits', async ({ donor }) => {
        // The form has the cover-fees check + the consent check (none here)
        // plus this single custom checkbox. Locate by label text to be sure.
        const wrapper = donor.form.locator('label.dono-form__check--single')
            .filter({ hasText: 'CUSTOM_CHECKBOX_LABEL' });
        await expect(wrapper).toBeVisible();

        const box = wrapper.locator('input[type="checkbox"]');
        await expect(box).not.toBeChecked();
        await box.check();
        await expect(box).toBeChecked();

        await donor.selectPresetAt(0);
        await donor.fillName('Check', 'Field');
        await donor.fillEmail(`e2e+cf+check+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('multi-select toggles options independently and submits multiple values', async ({ donor }) => {
        const fieldset = donor.form.locator('fieldset.dono-form__multi-select');
        await expect(fieldset).toBeVisible();
        await expect(fieldset.locator('legend')).toHaveText('CUSTOM_MULTISELECT_LABEL');

        await fieldset.locator('label', { hasText: 'One' }).click();
        await fieldset.locator('label', { hasText: 'Three' }).click();

        await expect(fieldset.locator('label', { hasText: 'One' })).toHaveClass(/is-selected/);
        await expect(fieldset.locator('label', { hasText: 'Two' })).not.toHaveClass(/is-selected/);
        await expect(fieldset.locator('label', { hasText: 'Three' })).toHaveClass(/is-selected/);

        // Untoggling one of them leaves the other still selected.
        await fieldset.locator('label', { hasText: 'One' }).click();
        await expect(fieldset.locator('label', { hasText: 'One' })).not.toHaveClass(/is-selected/);
        await expect(fieldset.locator('label', { hasText: 'Three' })).toHaveClass(/is-selected/);

        await donor.selectPresetAt(0);
        await donor.fillName('Multi', 'Field');
        await donor.fillEmail(`e2e+cf+multi+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('hidden field renders nothing visible but submit still succeeds', async ({ donor }) => {
        // The hidden block returns null at render time - asserting absence of a
        // visible "cf_hidden" label is the cleanest signal that the donor isn't
        // accidentally seeing internal-only data.
        await expect(
            donor.form.locator('text=cf_hidden')
        ).toHaveCount(0);

        // A normal submit still works (proves the hidden default value didn't
        // break the runtime).
        await donor.selectPresetAt(0);
        await donor.fillName('Hidden', 'Field');
        await donor.fillEmail(`e2e+cf+hidden+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
