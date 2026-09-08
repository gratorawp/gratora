/**
 * Use the seeded layout form from FUNDKIT_E2E_LAYOUT_FORM_PATH. Check block markers,
 * interactive controls, and full submission.
 */

import { test, expect } from '../fixtures/donor-form';

const FORM_PATH = process.env.FUNDKIT_E2E_LAYOUT_FORM_PATH ?? '';

test.describe('layout + content blocks', () => {
    test.skip(! FORM_PATH, 'set FUNDKIT_E2E_LAYOUT_FORM_PATH via `wp fundkit e2e-seed`');
    test.use({ formPath: FORM_PATH });

    test('heading block renders at the seeded level with the seeded text', async ({ donor }) => {
        const heading = donor.form.locator('h2.fundkit-form__heading', { hasText: 'LAYOUT_HEADING_TEXT' });
        await expect(heading).toBeVisible();
    });

    test('paragraph block renders the seeded text', async ({ donor }) => {
        const para = donor.form.locator('p.fundkit-form__paragraph', { hasText: 'LAYOUT_PARAGRAPH_TEXT' });
        await expect(para).toBeVisible();
    });

    test('html block renders the sanitised inner HTML', async ({ donor }) => {
        const html = donor.form.locator('.fundkit-form__html .layout-html-marker', { hasText: 'LAYOUT_HTML_TEXT' });
        await expect(html).toBeVisible();
    });

    test('divider renders as a styled <hr>', async ({ donor }) => {
        await expect(donor.form.locator('hr.fundkit-form__divider').first()).toBeVisible();
    });

    test('columns container renders its children side by side', async ({ donor }) => {
        const cols = donor.form.locator('.fundkit-block--columns').first();
        await expect(cols).toBeVisible();
        await expect(cols.locator('h4', { hasText: 'LAYOUT_COL_LEFT' })).toBeVisible();
        await expect(cols.locator('h4', { hasText: 'LAYOUT_COL_RIGHT' })).toBeVisible();
    });

    test('row renders nested donor fields together', async ({ donor }) => {
        // The seeded row holds fundkit/name + fundkit/email. The runtime groups
        // row-tagged donor fields into a `.fundkit-form__grid` wrapper (the
        // CSS-grid container that drives the row layout); the standalone
        // `.fundkit-form__row` class is the name field's INNER first+last
        // two-up wrapper, not the row block's wrapper.
        const grid = donor.form.locator('.fundkit-form__grid').first();
        await expect(grid).toBeVisible();
        await expect(grid.locator('input[autocomplete="given-name"]')).toBeVisible();
        await expect(grid.locator('input[type="email"]')).toBeVisible();
    });

    test('section block emits a visible section wrapper', async ({ donor }) => {
        // The section block renders a generic group container with a section
        // class. We assert that SOMETHING with the section class survived.
        const section = donor.form.locator('.fundkit-block--section, .fundkit-form__section').first();
        await expect(section).toBeVisible();
    });

    test('recurring-toggle renders frequencies and switching does not crash', async ({ donor }) => {
        const fs = donor.form.locator('fieldset.fundkit-form__frequency');
        await expect(fs).toBeVisible();
        await expect(fs.locator('legend')).toHaveText('LAYOUT_RECURRING_LABEL');

        // Two frequencies seeded: one-time + monthly. Options are buttons
        // (not labels) with role-pressed semantics.
        const options = fs.locator('button.fundkit-form__frequency-option');
        await expect(options).toHaveCount(2);

        // Pick the second frequency ("monthly").
        await options.nth(1).click();
        await expect(options.nth(1)).toHaveClass(/is-selected/);
    });

    test('fund-picker renders its fieldset (interactivity depends on seeded funds)', async ({ donor }) => {
        const fs = donor.form.locator('fieldset.fundkit-form__fund');
        await expect(fs).toBeVisible();
        // With Plugin::onActivation()'s seeded "general" fund present, at least
        // one selectable option should render. Skip if none for envs that
        // never ran activation.
        const options = fs.locator('input[type="radio"]');
        if (await options.count() > 0) {
            await expect(options.first()).toBeVisible();
        }
    });

    test('privacy-notice renders its seeded text', async ({ donor }) => {
        // privacy-notice goes through do_blocks() server-side then lands in a
        // fundkit-form__html wrapper; the marker survives somewhere in the form.
        await expect(donor.form).toContainText('LAYOUT_PRIVACY_TEXT');
    });

    test('goal block renders without breaking the form', async ({ donor }) => {
        // The seeded campaign has no goal_cents, so the goal block may render
        // empty or with a "no goal" placeholder. The hard assertion is that
        // the form is still data-fundkit-ready and submittable after the block
        // is in place.
        await expect(donor.form).toHaveAttribute('data-fundkit-ready', 'true');
    });

    test('a form with every layout + content block in place still submits cleanly', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('Layout', 'Submit');
        await donor.fillEmail(`e2e+layout+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
